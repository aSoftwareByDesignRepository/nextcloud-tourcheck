<?php

declare(strict_types=1);

namespace OCA\TourCheck\Service;

use OCA\TourCheck\Db\SiteTemplate;
use OCA\TourCheck\Db\SiteTemplateMapper;
use OCA\TourCheck\Exception\NotFoundException;
use OCP\IDBConnection;

/**
 * Vertical template packs — the one-tap onboarding content. Templates are
 * seeded by a repair step (idempotent upsert on tkey). Applying a template
 * creates checkpoints in walk order + a starter plan.
 */
class TemplateService
{
	/** @return list<array{tkey:string,vertical:string,name:string,payload:array}> */
	public static function seedData(): array
	{
		return [
			[
				'tkey' => 'security-night',
				'vertical' => 'security',
				'name' => 'Nachtwache / Night patrol',
				'payload' => [
					'checkpoints' => [
						['name' => 'Haupteingang', 'instructions' => 'Tür zugezogen, Schloss geprüft, Beleuchtung ok'],
						['name' => 'Hintereingang / Anlieferung', 'instructions' => 'Tor verschlossen, keine Fremdfahrzeuge'],
						['name' => 'Zaun Nord', 'instructions' => 'Zaun unbeschädigt, keine Durchbrüche'],
						['name' => 'Heizraum / Technik', 'instructions' => 'Keine Leckagen, Temperatur plausibel'],
						['name' => 'Aufzug / Treppenhaus', 'instructions' => 'Notbeleuchtung, keine offenen Fenster'],
					],
					'plan' => ['name' => 'Nachtrunde', 'recurrence' => ['type' => 'daily', 'time' => '22:00', 'duration_min' => 60]],
				],
			],
			[
				'tkey' => 'facility-weekly',
				'vertical' => 'facility',
				'name' => 'Facility-Runde wöchentlich',
				'payload' => [
					'checkpoints' => [
						['name' => 'Empfang / Lobby', 'instructions' => 'Sauberkeit, Beleuchtung'],
						['name' => 'Sanitäranlagen EG', 'instructions' => 'Verbrauchsmaterial, Defekte melden'],
						['name' => 'Keller / Lager', 'instructions' => 'Fluchtwege frei, keine Nässe'],
						['name' => 'Außenanlage', 'instructions' => 'Müll, Beleuchtung, Grünpflege'],
					],
					'plan' => ['name' => 'Wochenrunde', 'recurrence' => ['type' => 'weekly', 'days' => [5], 'time' => '14:00', 'duration_min' => 45]],
				],
			],
			[
				'tkey' => 'logistics-dock',
				'vertical' => 'logistics',
				'name' => 'Logistik / Dockkontrolle',
				'payload' => [
					'checkpoints' => [
						['name' => 'Dock 1–4', 'instructions' => 'Tore dicht, Rampen frei'],
						['name' => 'Dock 5–8', 'instructions' => 'Tore dicht, Rampen frei'],
						['name' => 'Kühllager', 'instructions' => 'Temperatur im Soll, Tür geschlossen'],
						['name' => 'Rettungswege Halle', 'instructions' => 'Frei von Paletten'],
					],
					'plan' => ['name' => 'Dockrunde', 'recurrence' => ['type' => 'interval', 'interval_hours' => 4, 'anchor' => '2026-01-01T06:00:00+01:00', 'duration_min' => 30]],
				],
			],
			[
				'tkey' => 'care-night',
				'vertical' => 'care',
				'name' => 'Pflege Nachtrunde',
				'payload' => [
					'checkpoints' => [
						['name' => 'Station A', 'instructions' => 'Ruhe, Türen, Notruf einsatzbereit'],
						['name' => 'Station B', 'instructions' => 'Ruhe, Türen, Notruf einsatzbereit'],
						['name' => 'Küche', 'instructions' => 'Herd aus, Medikamente verschlossen'],
						['name' => 'Ausgang Garten', 'instructions' => 'Tür verriegelt'],
					],
					'plan' => ['name' => 'Nachtrunde', 'recurrence' => ['type' => 'daily', 'time' => '02:00', 'duration_min' => 40]],
				],
			],
			[
				'tkey' => 'school-evening',
				'vertical' => 'property',
				'name' => 'Schulgebäude Abendrunde',
				'payload' => [
					'checkpoints' => [
						['name' => 'Eingang', 'instructions' => 'Verriegelt, Alarm scharf'],
						['name' => 'Flure EG', 'instructions' => 'Fenster zu, Licht aus'],
						['name' => 'Sporthalle', 'instructions' => 'Türen zu, keine Personen'],
						['name' => 'Hof / Spielplatz', 'instructions' => 'Keine Vandalismusschäden'],
					],
					'plan' => ['name' => 'Abendrunde', 'recurrence' => ['type' => 'daily', 'time' => '20:00', 'duration_min' => 45]],
				],
			],
		];
	}

	public function __construct(
		private readonly SiteTemplateMapper $templates,
		private readonly CheckpointService $checkpoints,
		private readonly PlanService $plans,
		private readonly AccessControlService $acl,
		private readonly SiteService $sites,
		private readonly IDBConnection $db,
	) {
	}

	/** Idempotent seed — upsert by tkey. Called by a repair step on install. */
	public function seed(): int
	{
		$count = 0;
		foreach (self::seedData() as $tpl) {
			$existing = $this->templates->findByKey($tpl['tkey']);
			if ($existing !== null) {
				$existing->setVertical($tpl['vertical']);
				$existing->setName($tpl['name']);
				$existing->setPayloadJson(json_encode($tpl['payload']));
				$this->templates->update($existing);
			} else {
				$t = new SiteTemplate();
				$t->setTkey($tpl['tkey']);
				$t->setVertical($tpl['vertical']);
				$t->setName($tpl['name']);
				$t->setPayloadJson(json_encode($tpl['payload']));
				$this->templates->insert($t);
			}
			$count++;
		}
		return $count;
	}

	/** @return list<SiteTemplate> */
	public function listAll(): array
	{
		return $this->templates->findAll();
	}

	/** Apply: checkpoints in order + a draft plan the operator reviews/activates. */
	public function apply(int $siteId, string $tkey): void
	{
		$this->sites->getVisible($siteId);
		$this->acl->assertCanManageSite($siteId);
		$tpl = $this->templates->findByKey($tkey);
		if ($tpl === null) {
			throw new NotFoundException();
		}
		$payload = json_decode((string)$tpl->getPayloadJson(), true);
		if (!is_array($payload) || !isset($payload['checkpoints'], $payload['plan'])) {
			throw new NotFoundException();
		}
		$this->db->beginTransaction();
		try {
			$cpIds = [];
			$order = 0;
			foreach ($payload['checkpoints'] as $cp) {
				[$created] = $this->checkpoints->create($siteId, [
					'name' => (string)$cp['name'],
					'instructions' => (string)($cp['instructions'] ?? ''),
					'order_hint' => $order,
				]);
				$cpIds[] = ['checkpoint_id' => (int)$created->getId(), 'offset_min' => $order * 10, 'before_min' => 15, 'after_min' => 60];
				$order++;
			}
			$this->plans->create($siteId, [
				'name' => (string)$payload['plan']['name'],
				'checkpoints' => $cpIds,
				'recurrence' => $payload['plan']['recurrence'],
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
