<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\Domain\Agent;
use OCA\HealthCheck\Domain\AiTasks;
use OCA\HealthCheck\Domain\Correlations;
use OCA\HealthCheck\Domain\Weight;
use OCA\HealthCheck\Domain\Chat;
use OCA\HealthCheck\Domain\Infographic;
use OCA\HealthCheck\Domain\Insights;
use OCP\AppFramework\Utility\ITimeFactory;

/** Read models for the UI, the report, the automation and the chat. Everything is scoped to one user. */
class DataService {
	public function __construct(
		private Store $store,
		private Settings $settings,
		private Files $files,
		private ImportService $import,
		private Calendar $calendar,
		private Outbound $outbound,
		private Notify $notify,
		private ITimeFactory $time,
	) {
	}

	public function status(string $uid): array {
		$last = $this->store->lastOkImport($uid);
		$imports = $this->store->imports($uid, 1);
		$today = $this->import->today($uid);
		$push = [
			'lastAt' => (int)$this->settings->get($uid, 'ingest_last', '0') ?: null,
			'lastDay' => $this->settings->get($uid, 'ingest_last_day') ?: null,
			'samples' => (int)$this->settings->get($uid, 'ingest_last_samples', '0'),
			'tokenSet' => $this->settings->get($uid, 'ingest_token_hash') !== '',
		];
		// freshness: whichever channel (zip export or daily push) delivered the newest day
		$newest = max((string)($last['lastDay'] ?? ''), (string)($push['lastDay'] ?? '')) ?: null;
		$age = ImportService::ageDays($newest, $today);
		return [
			'today' => $today,
			'lastImport' => $last,
			'lastAttempt' => $imports[0] ?? null,
			'push' => $push,
			'newestDay' => $newest,
			'ageDays' => $age,
			'stale' => $age !== null && $age > ImportService::STALE_DAYS,
			'staleAfterDays' => ImportService::STALE_DAYS,
			'pending' => $this->files->pendingInfo($uid),
			'running' => $this->import->running($uid),
		];
	}

	public function overview(string $uid): array {
		$this->settings->activate($uid);
		$rows = $this->store->days($uid);
		$workouts = $this->store->workouts($uid);
		$goals = $this->settings->goals($uid);
		$lastDay = $rows === [] ? null : $rows[count($rows) - 1]['day'];
		$ai = $this->store->aiReports($uid, 1)[0] ?? null;
		return [
			'status' => $this->status($uid),
			'hasData' => $rows !== [],
			'range' => $rows === [] ? null : ['from' => $rows[0]['day'], 'to' => $lastDay, 'days' => count($rows)],
			'rings' => Insights::rings($rows),
			'warnings' => Insights::warnings($rows),
			'latest' => array_slice($rows, -8),
			'week' => $rows === [] ? null : Insights::summary($rows, $workouts, $lastDay, 7, 28),
			'progress' => $lastDay === null ? null : Agent::progress($goals, $rows, $workouts, $lastDay),
			'streaks' => ['steps' => Agent::streak($rows, 'steps', $goals['steps']), 'sleep' => Agent::streak($rows, 'sleep_total', $goals['sleep_min'])],
			'recentWorkouts' => array_reverse(array_slice($workouts, -5)),
			'weight' => Weight::stats($rows, $goals['weight'], $this->import->today($uid)),
			'weightTrend' => Weight::movingAverage(array_values(array_filter(Weight::points($rows), fn ($p) => $p['day'] >= Insights::addDays($this->import->today($uid), -365)))),
			'weightEntries' => array_reverse(array_slice($this->store->weights($uid), -10)),
			'correlations' => Correlations::compute($rows, $workouts, 365),
			'aiLatest' => $this->aiLatest($uid),
			'ai' => $ai === null ? null : $ai + ['planEvaluation' => Agent::evaluatePlan($ai['plan'], $rows, $workouts, $this->settings->tz($uid))],
			'settings' => $this->settings->forUser($uid),
			'calendar' => $this->calendar->info($uid),
		];
	}

	public function series(string $uid, ?string $from, ?string $to): array {
		return ['days' => $this->store->days($uid, $from, $to), 'workouts' => $this->store->workouts($uid, $from, $to)];
	}

	public function report(string $uid, int $days): array {
		$days = in_array($days, [30, 90], true) ? $days : 30;
		$rows = $this->store->days($uid);
		$workouts = $this->store->workouts($uid);
		$end = $rows === [] ? $this->import->today($uid) : $rows[count($rows) - 1]['day'];
		$from = Insights::addDays($end, -($days - 1));
		$ai = $this->store->aiReports($uid, 1)[0] ?? null;
		return [
			'summary' => Insights::summary($rows, $workouts, $end, $days, max(91, $days)),
			'days' => Insights::range($rows, $from, $end),
			'workouts' => array_values(array_filter($workouts, fn ($w) => $w['day'] >= $from && $w['day'] <= $end)),
			'ai' => $ai === null ? null : ['createdAt' => $ai['createdAt'], 'model' => $ai['model'], 'doctor' => $ai['doctor'], 'doctorLang' => $ai['doctorLang'], 'to' => $ai['to']],
			'weight' => Weight::stats($rows, $this->settings->goals($uid)['weight'], $this->import->today($uid)),
			'weightTrend' => Weight::movingAverage(Weight::points(Insights::range($rows, Insights::addDays($end, -364), $end))),
			'doctorQuestions' => $this->aiLatest($uid)['doctorq'] ?? null,
			'status' => $this->status($uid),
			'lang' => $this->settings->lang($uid),
			'reportLang' => $this->settings->reportLang($uid),
		];
	}

	public function infographic(string $uid, string $period): array {
		$rows = $this->store->days($uid);
		return Infographic::build($rows, $this->store->workouts($uid), $this->settings->goals($uid), isset(Infographic::PERIODS[$period]) ? $period : 'week');
	}

	/** what the weekly automation receives: aggregates only */
	public function automationSummary(string $uid): array {
		$rows = $this->store->days($uid);
		$workouts = $this->store->workouts($uid);
		$goals = $this->settings->goals($uid);
		$end = $rows === [] ? $this->import->today($uid) : $rows[count($rows) - 1]['day'];
		$prev = $this->store->aiReports($uid, 4);
		$last = $prev[0] ?? null;
		$nextMonday = Insights::addDays(Agent::monday($this->import->today($uid)), 7);
		$thisMonday = Agent::monday($this->import->today($uid));
		$planStart = $this->import->today($uid) === $thisMonday ? $thisMonday : $nextMonday;
		$info = Infographic::build($rows, $workouts, $goals, 'week', $end);
		unset($info['heatmap']);
		$summary = Insights::summary($rows, $workouts, $end, 7, 91);
		$weightStats = Weight::stats($rows, $goals['weight'], $this->import->today($uid));
		$present = [];
		foreach (Insights::METRICS as $m) {
			$present[$m] = ['period' => $summary['metrics'][$m]['period']];
		}
		$goalCheck = AiTasks::goalCheck($goals, $summary, $present, 7, $weightStats);
		$lang = $this->settings->lang($uid);
		foreach ($goalCheck as $k => $g) {
			// ready-made wording in the report language; the model must not compare values with goals itself
			$goalCheck[$k]['status'] = AiTasks::STATUS[$lang][$g['verdict']] ?? $g['verdict'];
		}
		return [
			'app' => 'healthcheck', 'version' => 1,
			'lang' => $this->settings->lang($uid),
			'report_lang' => $this->settings->reportLang($uid),
			'today' => $this->import->today($uid),
			'data_status' => $this->status($uid),
			'summary' => $summary,
			'week_infographic' => $info,
			'goals' => $goals,
			'goal_check' => $goalCheck,
			'progress' => Agent::progress($goals, $rows, $workouts, $end),
			'next_week' => ['start' => $planStart, 'end' => Insights::addDays($planStart, 6),
				'targets' => Agent::weeklyTargets($goals, $rows, $workouts, $planStart)],
			'previous_plan' => $last === null ? null : ['plan' => $last['plan'], 'evaluation' => Agent::evaluatePlan($last['plan'], $rows, $workouts, $this->settings->tz($uid))],
			'memory' => array_map(fn ($r) => ['period_to' => $r['to'], 'summary' => mb_substr($r['summary'], 0, 1200)], $prev),
			'weight' => Weight::stats($rows, $goals['weight'], $this->import->today($uid)),
			'correlations_90d' => Correlations::compute($rows, $workouts, 90),
		];
	}

	/** stores the result of the automation and notifies */
	public function saveAutomationReport(string $uid, array $in): array {
		$clean = fn ($s, $n) => mb_substr(trim(strip_tags((string)$s)), 0, $n);
		$tips = [];
		foreach (array_slice(is_array($in['tips'] ?? null) ? $in['tips'] : [], 0, 5) as $t) {
			$t = is_array($t) ? ($t['text'] ?? '') : $t;
			if (($t = $clean($t, 500)) !== '') {
				$tips[] = $t;
			}
		}
		$summary = $clean($in['summary'] ?? '', 4000);
		if ($summary === '') {
			throw new \InvalidArgumentException('summary');
		}
		$rows = $this->store->days($uid);
		$workouts = $this->store->workouts($uid);
		$prev = $this->store->aiReports($uid, 1)[0] ?? null;
		$r = [
			'period_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['period_from'] ?? '')) ? $in['period_from'] : null,
			'period_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['period_to'] ?? '')) ? $in['period_to'] : null,
			'lang' => $this->settings->lang($uid),
			'model' => $clean($in['model'] ?? '', 100),
			'summary' => $summary,
			'tips' => $tips,
			'doctor' => $clean($in['doctor'] ?? '', 4000),
			'doctor_lang' => in_array($in['doctor_lang'] ?? '', ['de', 'en', 'it'], true) ? $in['doctor_lang'] : $this->settings->reportLang($uid),
			'plan' => Agent::sanitizePlan($in['plan'] ?? null),
			'evaluation' => $prev === null ? null : Agent::evaluatePlan($prev['plan'], $rows, $workouts, $this->settings->tz($uid)),
			'report_path' => $clean($in['report_path'] ?? '', 255) ?: null,
		];
		$id = $this->store->saveAi($uid, $r, $this->time->getTime());
		$this->notify->send($uid, 'report', ['day' => $r['period_to'] ?? ''], 'report', '#ai');
		return ['id' => $id, 'stored' => true, 'plan' => $r['plan'] !== null];
	}

	/** "Ask your data" */
	public function ask(string $uid, string $question): array {
		$q = Chat::cleanQuestion($question);
		if (mb_strlen($q) < 3) {
			throw new \InvalidArgumentException('question');
		}
		$now = $this->time->getTime();
		if (!Chat::allowed($this->store->chatTimes($uid, $now - 86400), $now)) {
			throw new \OverflowException('rate_limited');
		}
		$rows = $this->store->days($uid);
		$memory = array_map(fn ($r) => ['period_to' => $r['to'], 'summary' => mb_substr($r['summary'], 0, 800)], $this->store->aiReports($uid, 4));
		$ctx = Chat::context($rows, $this->store->workouts($uid), $this->settings->goals($uid), $memory);
		$lang = $this->settings->lang($uid);
		try {
			$res = $this->outbound->chat(Chat::messages($q, $ctx, $lang));
			$this->store->saveChat($uid, $q, $res['answer'], $res['model'], 'ok', $now);
			return ['question' => $q, 'answer' => $res['answer'], 'model' => $res['model'], 'at' => $now];
		} catch (\Throwable $e) {
			$this->store->saveChat($uid, $q, '', '', 'error', $now);
			throw $e;
		}
	}

	// ---- manual weigh-ins ----------------------------------------------------------------------------------------
	public function addWeight(string $uid, array $in): array {
		$day = (string)($in['day'] ?? '') ?: $this->import->today($uid);
		$kg = str_replace(',', '.', (string)($in['weight'] ?? ''));
		$fat = str_replace(',', '.', (string)($in['bodyfat'] ?? ''));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > $this->import->today($uid) || $day < '2000-01-01') {
			throw new \InvalidArgumentException('day');
		}
		if (!is_numeric($kg) || (float)$kg <= 20 || (float)$kg > 300) {
			throw new \InvalidArgumentException('weight');
		}
		if ($fat !== '' && (!is_numeric($fat) || (float)$fat < 2 || (float)$fat > 70)) {
			throw new \InvalidArgumentException('bodyfat');
		}
		$this->store->saveWeight($uid, $day, round((float)$kg, 2), $fat === '' ? null : round((float)$fat, 1), $this->time->getTime());
		$this->notify->dismiss($uid, 'nudge-weigh_in');
		return ['entries' => array_reverse($this->store->weights($uid))];
	}

	public function deleteWeight(string $uid, int $id): array {
		if (!$this->store->deleteWeight($uid, $id)) {
			throw new \DomainException('not_found');
		}
		return ['entries' => array_reverse($this->store->weights($uid))];
	}

	// ---- on-demand AI functions ----------------------------------------------------------------------------------
	/** latest stored result per kind (check-up, doctor questions) */
	public function aiLatest(string $uid): array {
		$out = [];
		foreach (['checkup', 'doctorq'] as $k) {
			$c = $this->store->chats($uid, 1, $k)[0] ?? null;
			if ($c !== null && $c['status'] === 'ok') {
				$out[$k] = ['at' => $c['at'], 'model' => $c['model'], 'request' => $c['question'], 'result' => json_decode((string)$c['answer'], true)];
			}
		}
		return $out;
	}

	public function aiTask(string $uid, string $kind, array $in): array {
		if (!in_array($kind, AiTasks::KINDS, true)) {
			throw new \InvalidArgumentException('kind');
		}
		$now = $this->time->getTime();
		if (!\OCA\HealthCheck\Domain\Chat::allowed($this->store->chatTimes($uid, $now - 86400), $now)) {
			throw new \OverflowException('rate_limited');
		}
		$rows = $this->store->days($uid);
		$workouts = $this->store->workouts($uid);
		$goals = $this->settings->goals($uid);
		$today = $this->import->today($uid);
		$lang = $this->settings->lang($uid);
		if ($kind === 'explain') {
			$key = (string)($in['key'] ?? '');
			$w = null;
			foreach (Insights::warnings($rows) as $x) {
				if ($x['key'] === $key) {
					$w = $x;
				}
			}
			if ($w === null) {
				throw new \InvalidArgumentException('key');
			}
			$messages = AiTasks::explainMessages(AiTasks::explainContext($w, $rows, $workouts), $lang);
			$request = 'explain:' . $key . ':' . $w['day'];
		} else {
			$days = (int)($in['days'] ?? 30);
			$days = in_array($days, [30, 90, 365], true) ? $days : 30;
			$ctx = AiTasks::periodContext($rows, $workouts, $goals, $days, $today);
			if ($kind === 'doctorq') {
				$lang = in_array((string)($in['lang'] ?? ''), ['de', 'en', 'it'], true) ? (string)$in['lang'] : $this->settings->reportLang($uid);
				$messages = AiTasks::doctorMessages($ctx, $lang);
			} else {
				$messages = AiTasks::checkupMessages($ctx, $lang);
			}
			$request = $kind . ':' . $days . ':' . $lang;
		}
		try {
			$res = $this->outbound->chat($messages);
			$clean = AiTasks::clean($kind, AiTasks::json($res['answer']));
			if ($clean === null) {
				throw new \RuntimeException('ai_empty');
			}
			$this->store->saveChat($uid, $request, (string)json_encode($clean, JSON_UNESCAPED_UNICODE), $res['model'], 'ok', $now, $kind);
			return ['kind' => $kind, 'request' => $request, 'result' => $clean, 'model' => $res['model'], 'at' => $now];
		} catch (\Throwable $e) {
			$this->store->saveChat($uid, $request, '', '', 'error', $now, $kind);
			throw $e;
		}
	}

	public function deleteAll(string $uid, bool $calendar): array {
		$out = $this->store->deleteAll($uid);
		if ($calendar) {
			$out['calendar'] = $this->calendar->remove($uid);
		}
		foreach (['import_lock', 'failed', 'stale_notified'] as $k) {
			$this->settings->del($uid, $k);
		}
		return $out;
	}
}
