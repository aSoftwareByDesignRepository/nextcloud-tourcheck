<?php

declare(strict_types=1);

namespace OCA\TourCheck\Tests\Unit\Service;

use OCA\TourCheck\Db\AnchorMapper;
use OCA\TourCheck\Db\CheckEvent;
use OCA\TourCheck\Db\CheckEventMapper;
use OCA\TourCheck\Db\Incident;
use OCA\TourCheck\Db\IncidentMapper;
use OCA\TourCheck\Db\JournalEntryMapper;
use OCA\TourCheck\Db\ReportDeliveryMapper;
use OCA\TourCheck\Db\ReportSchedMapper;
use OCA\TourCheck\Db\Round;
use OCA\TourCheck\Db\RoundCheckpoint;
use OCA\TourCheck\Db\RoundCheckpointMapper;
use OCA\TourCheck\Db\RoundMapper;
use OCA\TourCheck\Db\Site;
use OCA\TourCheck\Service\AccessControlService;
use OCA\TourCheck\Service\ChainService;
use OCA\TourCheck\Service\ReportService;
use OCA\TourCheck\Service\SettingsService;
use OCA\TourCheck\Service\SiteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * ODS export (spec AC-3 / GA-7): a real ODF spreadsheet package — mimetype
 * STORED first, manifest + content present, rows neutralized like CSV.
 */
final class OdsExportTest extends TestCase
{
	private IncidentMapper&MockObject $incidents;
	private JournalEntryMapper&MockObject $journal;
	private AnchorMapper&MockObject $anchors;
	private ReportService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$rounds = $this->createMock(RoundMapper::class);
		$events = $this->createMock(CheckEventMapper::class);
		$roundCps = $this->createMock(RoundCheckpointMapper::class);
		$this->incidents = $this->createMock(IncidentMapper::class);
		$this->anchors = $this->createMock(AnchorMapper::class);
		$this->journal = $this->createMock(JournalEntryMapper::class);
		$this->journal->method('countForSite')->willReturn(0);
		// findLatestAnchored/findHead stay unstubbed (null) — tests override per case.
		$acl = $this->createMock(AccessControlService::class);
		$acl->method('assertCanViewSite')->willReturn('planner1');
		$sites = $this->createMock(SiteService::class);
		$site = new Site();
		$site->setId(7);
		$site->setName('=dangerous+name');
		$site->setTz('Europe/Berlin');
		$sites->method('getVisible')->willReturn($site);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, $p = []) => vsprintf($t, (array)$p));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-20T12:00:00Z'));

		$round = new Round();
		$round->setId(3);
		$round->setSiteId(7);
		$round->setStatus('completed');
		$round->setScheduledStart(new \DateTime('2026-10-01T08:00:00Z'));
		$round->setWindowEnd(new \DateTime('2026-10-01T10:00:00Z'));
		$rounds->method('findScheduledBetween')->willReturn([$round]);
		$cp = new RoundCheckpoint();
		$cp->setId(91);
		$cp->setNameSnap('@SUM(evil)');
		$cp->setStatus('done');
		$roundCps->method('findForRound')->willReturn([$cp]);
		$e = new CheckEvent();
		$e->setId(1);
		$e->setRoundCpId(91);
		$e->setMethod('scan_qr');
		$e->setClientTs(new \DateTime('2026-10-01T09:00:00Z'));
		$e->setServerReceivedAt(new \DateTime('2026-10-01T09:00:01Z'));
		$e->setFlaggedEarly(0);
		$e->setLateSync(0);
		$e->setOrphan(0);
		$e->setTombstoned(0);
		$e->setActorUid('worker-anna');
		$events->method('findForSiteBetween')->willReturn([$e]);

		$this->svc = new ReportService(
			$rounds, $events, $roundCps,
			$this->createMock(ReportSchedMapper::class), $this->createMock(ReportDeliveryMapper::class),
			$this->journal, $this->anchors, $this->incidents,
			$acl, $sites, $this->createMock(SettingsService::class),
			$this->createMock(IUserManager::class), $this->createMock(IRootFolder::class),
			$this->createMock(IAppData::class), $this->createMock(IMailer::class),
			$this->createMock(LoggerInterface::class), $time,
			$this->createMock(IDBConnection::class), $l10n,
			$this->createMock(ChainService::class),
		);
	}

	/** @return array<string,string> filename → contents */
	private function unzip(string $blob): array
	{
		$tmp = tempnam(sys_get_temp_dir(), 'ods-test-');
		self::assertNotFalse($tmp);
		file_put_contents($tmp, $blob);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp));
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$out[$zip->getNameIndex($i)] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		@unlink($tmp);
		return $out;
	}

	public function testPackageIsValidOdf(): void
	{
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$files = $this->unzip($this->svc->buildOds(7, '2026-10'));
		self::assertSame('application/vnd.oasis.opendocument.spreadsheet', $files['mimetype'] ?? null);
		self::assertArrayHasKey('content.xml', $files);
		self::assertArrayHasKey('META-INF/manifest.xml', $files);
		self::assertArrayHasKey('styles.xml', $files);
		self::assertArrayHasKey('meta.xml', $files);
		self::assertStringContainsString('table:table', $files['content.xml']);
	}

	public function testSheetsContainRoundsAndSummary(): void
	{
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$files = $this->unzip($this->svc->buildOds(7, '2026-10'));
		$content = $files['content.xml'];
		self::assertStringContainsString('table:name="Summary"', $content);
		self::assertStringContainsString('table:name="Rounds"', $content);
		self::assertStringContainsString('table:name="Incidents"', $content);
		self::assertStringContainsString('sla_on_time_pct', $content);
		// The checkpoint row must carry through.
		self::assertStringContainsString('scan_qr', $content);
	}

	public function testFormulaLeadingCharsNeutralizedLikeCsv(): void
	{
		$incident = new Incident();
		$incident->setId(9);
		$incident->setSiteId(7);
		$incident->setCategory('damage');
		$incident->setSeverity('high');
		$incident->setStatus('open');
		$incident->setTitle('=HYPERLINK("evil")');
		$incident->setCreatedAt(new \DateTime('2026-10-01T09:00:00Z'));
		$this->incidents->method('findForSiteBetween')->willReturn([$incident]);
		$files = $this->unzip($this->svc->buildOds(7, '2026-10'));
		$content = $files['content.xml'];
		self::assertStringContainsString('&apos;@SUM(evil)', $content);      // checkpoint name
		self::assertStringContainsString('&apos;=HYPERLINK', $content);     // incident title
		self::assertStringContainsString('&apos;=dangerous+name', $content); // site name
	}

	public function testClientAudienceRedactsWorkerIdentities(): void
	{
		// share_policy audience=client: operational proof without the
		// per-worker surveillance trail (object-client recipients).
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$csv = $this->svc->buildCsv(7, '2026-10', 'client');
		self::assertStringContainsString('REDACTED', $csv);
		self::assertStringNotContainsString('worker-anna', $csv);
		self::assertStringContainsString('audience;client', $csv);

		$internal = $this->svc->buildCsv(7, '2026-10', 'internal');
		self::assertStringContainsString('worker-anna', $internal);
	}

	public function testCsvPreambleCarriesChainHeadAndAnchorEvidence(): void
	{
		// NN-13/GA-12: the export asserts integrity only with the verifiable
		// artifact attached — chain-head hash + explicit anchor status.
		$head = new \OCA\TourCheck\Db\JournalEntry();
		$head->setSeq(41);
		$head->setEntryHash('ab12ef34');
		$this->journal->method('findHead')->willReturn($head);
		$anchor = new \OCA\TourCheck\Db\Anchor();
		$anchor->setSeq(5);
		$anchor->setStatus('anchored');
		$anchor->setAnchoredAt(new \DateTime('2026-10-19T06:00:00Z'));
		$this->anchors->method('findLatestAnchored')->willReturn($anchor);
		$this->incidents->method('findForSiteBetween')->willReturn([]);

		$csv = $this->svc->buildCsv(7, '2026-10');
		self::assertStringContainsString('evidence_chain_head_seq;41', $csv);
		self::assertStringContainsString('evidence_chain_head_hash;ab12ef34', $csv);
		self::assertStringContainsString('evidence_anchor_status;ANCHORED', $csv);
		self::assertStringContainsString('evidence_anchor_seq;5', $csv);
	}

	public function testCsvPreambleExplicitlyUnanchoredWithoutHead(): void
	{
		// Null head/anchor → explicit NONE/UNANCHORED markers, never blanks.
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$csv = $this->svc->buildCsv(7, '2026-10');
		self::assertStringContainsString('evidence_chain_head_hash;NONE', $csv);
		self::assertStringContainsString('evidence_anchor_status;UNANCHORED', $csv);
	}

	public function testOdsSummaryCarriesChainHeadHashAndAnchorStatus(): void
	{
		$head = new \OCA\TourCheck\Db\JournalEntry();
		$head->setSeq(41);
		$head->setEntryHash('cafebabe99');
		$this->journal->method('findHead')->willReturn($head);
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$files = $this->unzip($this->svc->buildOds(7, '2026-10'));
		$content = $files['content.xml'];
		self::assertStringContainsString('chain_head_hash', $content);
		self::assertStringContainsString('cafebabe99', $content);
		self::assertStringContainsString('anchor_status', $content);
		self::assertStringContainsString('UNANCHORED', $content);
	}

	public function testXmlWellFormed(): void
	{
		$this->incidents->method('findForSiteBetween')->willReturn([]);
		$files = $this->unzip($this->svc->buildOds(7, '2026-10'));
		foreach (['content.xml', 'styles.xml', 'meta.xml', 'META-INF/manifest.xml'] as $f) {
			$doc = new \DOMDocument();
			self::assertTrue($doc->loadXML($files[$f]), "$f must be well-formed XML");
		}
	}
}
