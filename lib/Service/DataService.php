<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\Domain\Agent;
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
		$age = ImportService::ageDays($last['lastDay'] ?? null, $today);
		return [
			'today' => $today,
			'lastImport' => $last,
			'lastAttempt' => $imports[0] ?? null,
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
		return [
			'app' => 'healthcheck', 'version' => 1,
			'lang' => $this->settings->lang($uid),
			'report_lang' => $this->settings->reportLang($uid),
			'today' => $this->import->today($uid),
			'data_status' => $this->status($uid),
			'summary' => Insights::summary($rows, $workouts, $end, 7, 91),
			'week_infographic' => $info,
			'goals' => $goals,
			'progress' => Agent::progress($goals, $rows, $workouts, $end),
			'next_week' => ['start' => $planStart, 'end' => Insights::addDays($planStart, 6),
				'targets' => Agent::weeklyTargets($goals, $rows, $workouts, $planStart)],
			'previous_plan' => $last === null ? null : ['plan' => $last['plan'], 'evaluation' => Agent::evaluatePlan($last['plan'], $rows, $workouts, $this->settings->tz($uid))],
			'memory' => array_map(fn ($r) => ['period_to' => $r['to'], 'summary' => mb_substr($r['summary'], 0, 1200)], $prev),
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
