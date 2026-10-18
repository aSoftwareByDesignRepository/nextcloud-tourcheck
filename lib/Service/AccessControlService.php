<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Constants;
use OCA\TourCheck\Db\SiteMapper;
use OCA\TourCheck\Db\SiteMemberMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCA\TourCheck\Exception\ValidationException;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Single authority for who may do what. Layers (all must pass):
 *  1. Access door (open/restricted + allowed groups/users)
 *  2. App roles: NC groups tourcheck_{admin,planner,supervisor,worker}
 *     plus delegated app admins (settings list) and NC instance admin.
 *  3. Site scope: tck_site_member.role for site-scoped actions.
 * Not-found and not-yours collapse to the same NotFoundException (uniform 404).
 */
class AccessControlService
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IRequest $request,
		private readonly IProvider $tokenProvider,
		private readonly SiteMapper $siteMapper,
		private readonly SiteMemberMapper $members,
		private readonly SettingsService $settings,
	) {
	}

	public function currentUid(): ?string
	{
		// An explicit Basic credential is the actor, not the ambient session.
		// The companion's HTTP stack shares a persistent cookie jar (sign-out
		// does not clear it), so a stale session cookie would otherwise
		// shadow the credential and attribute every companion write — check-
		// ins, incidents, positions — to the wrong user.
		$auth = trim((string)$this->request->getHeader('Authorization'));
		if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m) === 1) {
			$decoded = base64_decode($m[1], true);
			if ($decoded !== false && str_contains($decoded, ':')) {
				[$login, $secret] = explode(':', $decoded, 2);
				if ($login !== '' && $secret !== '') {
					return $this->uidFromBasicCredential($login, $secret);
				}
			}
		}
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * Validate `login:secret` and return the uid it authenticates as —
	 * Login Flow / CLI app password via the token provider (the token's
	 * owner is authoritative, never the claimed login), or account password
	 * via the user backends. Null = credential does not authenticate.
	 */
	private function uidFromBasicCredential(string $login, string $secret): ?string
	{
		try {
			$token = $this->tokenProvider->getToken($secret);
			if (hash_equals($token->getUID(), $login) || hash_equals($token->getLoginName(), $login)) {
				return $token->getUID();
			}
		} catch (InvalidTokenException) {
			// not a token — try the password backends below
		}
		$user = $this->userManager->checkPassword($login, $secret);
		return $user === false ? null : $user->getUID();
	}

	public function requireUid(): string
	{
		$uid = $this->currentUid();
		if ($uid === null) {
			throw new NotFoundException();
		}
		return $uid;
	}

	public function isNcAdmin(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		return $uid !== null && $this->groupManager->isAdmin($uid);
	}

	public function isAppAdmin(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		if ($uid === null) {
			return false;
		}
		if ($this->isNcAdmin($uid)) {
			return true;
		}
		if (in_array($uid, $this->settings->getAppAdmins(), true)) {
			return true;
		}
		return $this->groupManager->isInGroup($uid, Constants::GROUP_ADMIN);
	}

	public function hasAppAccess(?string $uid = null): bool
	{
		$uid ??= $this->currentUid();
		if ($uid === null) {
			return false;
		}
		if ($this->settings->getAccessMode() === 'open') {
			return true;
		}
		if ($this->isAppAdmin($uid)) {
			return true;
		}
		if (in_array($uid, $this->settings->getAllowedUsers(), true)) {
			return true;
		}
		foreach ($this->settings->getAllowedGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}
		// Any tourcheck_* role group grants base access as well.
		foreach ([Constants::GROUP_ADMIN, Constants::GROUP_PLANNER, Constants::GROUP_SUPERVISOR, Constants::GROUP_WORKER] as $g) {
			if ($this->groupManager->isInGroup($uid, $g)) {
				return true;
			}
		}
		return false;
	}

	/** Gate for pages/API entry — uniform not-found, no "the app exists but you can't" leak. */
	public function assertAppAccess(): string
	{
		$uid = $this->requireUid();
		if (!$this->hasAppAccess($uid)) {
			throw new NotFoundException();
		}
		return $uid;
	}

	public function assertAppAdmin(): string
	{
		$uid = $this->assertAppAccess();
		if (!$this->isAppAdmin($uid)) {
			throw new NotFoundException();
		}
		return $uid;
	}

	private function inGroup(string $uid, string $group): bool
	{
		return $this->groupManager->isInGroup($uid, $group);
	}

	/** Global role check: group membership OR any site_member row with that role. */
	public function hasRole(string $uid, string $role): bool
	{
		if ($this->isAppAdmin($uid)) {
			return true;
		}
		$group = 'tourcheck_' . $role;
		if ($this->inGroup($uid, $group)) {
			return true;
		}
		return $this->members->findByUidAndRole($uid, $role) !== [];
	}

	public function canPlan(string $uid): bool
	{
		return $this->hasRole($uid, Constants::ROLE_ADMIN) || $this->hasRole($uid, Constants::ROLE_PLANNER);
	}

	public function canSupervise(string $uid): bool
	{
		return $this->canPlan($uid) || $this->hasRole($uid, Constants::ROLE_SUPERVISOR);
	}

	/**
	 * True when the user can manage at least one site — app admin/planner
	 * or a site-scoped admin/planner membership. Equivalent to canPlan
	 * (hasRole already covers group OR membership per role); named for
	 * the picker-gating call sites. Gates the shared uid picker (user
	 * enumeration must stay a manage privilege).
	 */
	public function canManageAnySite(string $uid): bool
	{
		return $this->canPlan($uid);
	}

	/** Site-scoped role from tck_site_member; app admin/planner group implies manage. */
	public function siteRole(string $uid, int $siteId): ?string
	{
		if ($this->isAppAdmin($uid)) {
			return Constants::ROLE_ADMIN;
		}
		if ($this->inGroup($uid, Constants::GROUP_PLANNER)) {
			return Constants::ROLE_PLANNER;
		}
		return $this->members->roleFor($siteId, $uid);
	}

	/** Manage = admin or planner at site scope. Uniform 404 when not. */
	public function assertCanManageSite(int $siteId): string
	{
		$uid = $this->assertAppAccess();
		$this->assertSiteExists($siteId);
		$role = $this->siteRole($uid, $siteId);
		if (!in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER], true)) {
			throw new NotFoundException();
		}
		return $uid;
	}

	/** Attest/journal/close = supervisor+ at site scope. */
	public function assertCanSuperviseSite(int $siteId): string
	{
		$uid = $this->assertAppAccess();
		$this->assertSiteExists($siteId);
		$role = $this->siteRole($uid, $siteId);
		if (!in_array($role, [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER, Constants::ROLE_SUPERVISOR], true)) {
			throw new NotFoundException();
		}
		return $uid;
	}

	/** Read evidence = worker+ at site scope. */
	public function assertCanViewSite(int $siteId): string
	{
		$uid = $this->assertAppAccess();
		$this->assertSiteExists($siteId);
		if ($this->siteRole($uid, $siteId) === null) {
			throw new NotFoundException();
		}
		return $uid;
	}

	/**
	 * Site-scoped asserts must fail even when an app/planner role is implied
	 * globally — otherwise admins can probe which site ids exist (oracle)
	 * and endpoints operate on rows of a deleted site.
	 */
	private function assertSiteExists(int $siteId): void
	{
		if ($this->siteMapper->find($siteId) === null) {
			throw new NotFoundException();
		}
	}

	/** Site ids the user may see at all (dashboard scoping). @return list<int> */
	public function visibleSiteIds(string $uid): array
	{
		if ($this->isAppAdmin($uid) || $this->inGroup($uid, Constants::GROUP_PLANNER)) {
			return []; // empty = unrestricted, callers handle
		}
		return $this->members->siteIdsForUid($uid);
	}

	/**
	 * Notification recipients for a site: site members with supervisor+ role,
	 * planner/admin group members, and delegated app admins.
	 * @return list<string>
	 */
	public function supervisorRecipients(int $siteId): array
	{
		$uids = [];
		foreach ($this->members->findBySite($siteId) as $m) {
			if (in_array($m->getRole(), [Constants::ROLE_ADMIN, Constants::ROLE_PLANNER, Constants::ROLE_SUPERVISOR], true)) {
				$uids[$m->getUid()] = true;
			}
		}
		foreach ([Constants::GROUP_PLANNER, Constants::GROUP_ADMIN, Constants::GROUP_SUPERVISOR] as $g) {
			foreach ($this->groupManager->get($g)?->getUsers() ?? [] as $u) {
				$uids[$u->getUID()] = true;
			}
		}
		foreach ($this->settings->getAppAdmins() as $uid) {
			$uids[$uid] = true;
		}
		return array_values(array_filter(array_keys($uids), fn ($uid) => $this->userManager->userExists($uid) && $this->hasAppAccess($uid)));
	}

	/**
	 * Ghost-UID/GID defense: existence AND app-relevance both checked.
	 * 422, not 404 — every caller validates a submitted field value, and a
	 * ghost in a field is bad input (uniform-404 is reserved for URL ids).
	 */
	public function assertValidUid(string $uid): void
	{
		if ($uid === '' || strlen($uid) > 64 || !$this->userManager->userExists($uid)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown user.', ['uid' => 'unknown']);
		}
	}

	public function assertValidGid(string $gid): void
	{
		if ($gid === '' || strlen($gid) > 64 || !$this->groupManager->groupExists($gid)) {
			throw new ValidationException(Constants::ERR_VALIDATION, 'Unknown group.', ['gid' => 'unknown']);
		}
	}
}
