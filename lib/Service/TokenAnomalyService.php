<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Support\LockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IMemcache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * GA-3 / Observability: token-anomaly detection.
 *
 * Invalid-token submissions > 20 / 5 min — counted GLOBALLY and PER-IP —
 * emit one `token_invalid_burst` structured event per window per scope.
 * Invalid tokens name no site by design, so the signal goes to the log
 * (hashed IP, never raw) + an NC notification to instance/app admins —
 * it cannot be chained into a per-site journal.
 *
 * Attributable failures (revoked/rotated tag, archived checkpoint) count
 * per site; > 20 / 5 min emits ONE chained `token_anomaly_burst` journal
 * entry + a deduped site notification. Individual hits are counted only —
 * the resolve path must stay write-free below threshold or an attacker
 * mints chained journal rows with nothing but a dead tag (amplification).
 *
 * Counters live in the distributed memcache (same backend as the rate
 * limiter) — correctness across PHP workers; when the cache backend is
 * per-process, detection degrades but capture never blocks on it.
 */
class TokenAnomalyService
{
	private const WINDOW = 300;
	private const THRESHOLD = 20;

	private readonly IMemcache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $locking,
		private readonly INotificationManager $notifications,
		private readonly IGroupManager $groupManager,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
		private readonly ?ChainService $chain = null,
		private readonly ?NotificationService $notify = null,
		private readonly ?SiteService $sites = null,
	) {
		$this->cache = $cacheFactory->createDistributed('tck_anomaly');
	}

	/** Hash IP for correlation without storing the raw address (no PII payloads). */
	public static function ipHash(?string $ip): string
	{
		return $ip === null || $ip === '' ? 'none' : substr(hash('sha256', $ip), 0, 16);
	}

	/**
	 * An unresolvable token submission — count global + per-IP; at >THRESHOLD
	 * per WINDOW emit once per scope per window (atomic cache `add`).
	 */
	public function recordInvalid(?string $ip): void
	{
		try {
			$global = $this->bump('invalid|global');
			if ($global > self::THRESHOLD) {
				$this->emitGlobalBurst('global', $global, null);
			}
			if ($ip !== null && $ip !== '') {
				$perIp = $this->bump('invalid|ip|' . self::ipHash($ip));
				if ($perIp > self::THRESHOLD) {
					$this->emitGlobalBurst('ip', $perIp, self::ipHash($ip));
				}
			}
		} catch (\Throwable $e) {
			// Detection must never break capture — log and move on.
			$this->logger->warning('tourcheck: token-anomaly counter failed', ['e' => $e->getMessage()]);
		}
	}

	/**
	 * A submitted token that resolves to a KNOWN-but-dead row: rotated/revoked
	 * tag or tag on an archived checkpoint. Site-attributable, so bursts here
	 * are chained journal signals + site notifications.
	 */
	public function recordSiteHit(int $siteId, string $kind): void
	{
		$kind = preg_replace('/[^a-z0-9_]/', '', $kind);
		try {
			$count = $this->bump('site|' . $siteId . '|' . $kind);
			if ($count <= self::THRESHOLD) {
				return;
			}
			$emitKey = 'emit|site|' . $siteId . '|' . $kind;
			if (!$this->cache->add($emitKey, 1, self::WINDOW)) {
				return; // already emitted this window
			}
			$body = 'token_anomaly_burst:' . $kind;
			$this->logger->warning('tourcheck: ' . $body, ['signal' => $body, 'site_id' => $siteId, 'count' => $count, 'window_s' => self::WINDOW]);
			if ($this->chain !== null) {
				try {
					$this->chain->append($siteId, [
						'kind' => Constants::KIND_ADMIN,
						'body' => $body,
						'payloadRef' => 'signal',
						'payloadFields' => ['signal' => $body, 'count' => $count, 'window_s' => self::WINDOW],
					]);
				} catch (\Throwable $e) {
					$this->logger->warning('tourcheck: anomaly journal failed', ['site' => $siteId, 'e' => $e->getMessage()]);
				}
			}
			if ($this->notify !== null && $this->sites !== null) {
				$site = $this->sites->findById($siteId);
				if ($site !== null) {
					$this->notify->notifySite(
						$siteId,
						'token_anomaly:' . $siteId . ':' . $kind . ':' . intdiv($this->timeFactory->getTime(), self::WINDOW),
						'token_anomaly_burst',
						'More than ' . self::THRESHOLD . ' ' . str_replace('_', ' ', $kind) . ' token hits in ' . (int)(self::WINDOW / 60) . ' minutes — rotated tags still posted, or cloned/burned tokens in circulation.',
						$site
					);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('tourcheck: site-anomaly counter failed', ['e' => $e->getMessage()]);
		}
	}

	/**
	 * Sliding-window counter; the read-modify-write is serialized per key so
	 * concurrent invalid submissions cannot lost-update under the threshold.
	 */
	private function bump(string $subject): int
	{
		$now = $this->timeFactory->getTime();
		try {
			return (int)LockGuard::run($this->locking, 'tck-an-' . md5($subject), function () use ($subject, $now): int {
				$raw = $this->cache->get($subject);
				$hits = is_array($raw) ? array_values(array_filter($raw, static fn ($t) => is_int($t) && $t > $now - self::WINDOW)) : [];
				$hits[] = $now;
				$this->cache->set($subject, $hits, self::WINDOW + 60);
				return count($hits);
			}, 20);
		} catch (LockedException) {
			// Contention on the counter IS load — count it via a cheap
			// non-blocking increment marker rather than dropping the signal.
			$this->cache->set($subject . '|spill', $now, self::WINDOW + 60);
			return self::THRESHOLD + 1;
		}
	}

	private function emitGlobalBurst(string $scope, int $count, ?string $ipHash): void
	{
		if (!$this->cache->add('emit|' . $scope . '|' . ($ipHash ?? ''), 1, self::WINDOW)) {
			return; // already emitted this window
		}
		$ctx = ['signal' => 'token_invalid_burst', 'scope' => $scope, 'count' => $count, 'window_s' => self::WINDOW];
		if ($ipHash !== null) {
			$ctx['ip_hash'] = $ipHash;
		}
		$this->logger->warning('tourcheck: token_invalid_burst', $ctx);
		foreach ($this->adminRecipients() as $uid) {
			try {
				$n = $this->notifications->createNotification();
				$n->setApp(Constants::APP_ID)
					->setUser($uid)
					->setDateTime(new \DateTime())
					// object_id ≤64 chars, message ≤64 chars — InvalidValueException
					// otherwise and the burst signal is silently undelivered.
					->setObject('security', mb_substr('token_invalid_burst_' . $scope . '_' . ($ipHash ?? ''), 0, 64))
					->setSubject('token_invalid_burst', ['scope' => $scope])
					->setMessage('Invalid-token burst (scope: ' . $scope . ')');
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				$this->logger->warning('tourcheck: burst notification failed', ['uid' => $uid, 'e' => $e->getMessage()]);
			}
		}
	}

	/** NC admins + app admin group + delegated app admins. @return list<string> */
	private function adminRecipients(): array
	{
		$uids = [];
		foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $u) {
			$uids[$u->getUID()] = true;
		}
		foreach ($this->groupManager->get(Constants::GROUP_ADMIN)?->getUsers() ?? [] as $u) {
			$uids[$u->getUID()] = true;
		}
		foreach ($this->settings->getAppAdmins() as $uid) {
			$uids[$uid] = true;
		}
		return array_keys($uids);
	}
}
