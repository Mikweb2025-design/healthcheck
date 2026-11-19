<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Agent;
use OCA\HealthCheck\Domain\Chat;
use OCA\HealthCheck\Domain\Infographic;
use OCA\HealthCheck\Domain\Insights;
use OCA\HealthCheck\Tests\TestCase;

class AgentTest extends TestCase {
	private function rows(int $n = 60, string $end = '2026-03-31', ?callable $patch = null): array {
		$rows = [];
		for ($i = 0; $i < $n; $i++) {
			$day = Insights::addDays($end, -($n - 1 - $i));
			$r = ['day' => $day, 'steps' => 6000, 'sleep_total' => 400, 'sleep_deep' => 60, 'sleep_rem' => 80, 'sleep_core' => 260, 'active_kcal' => 300,
				'resting_hr' => 56.0, 'spo2_avg' => 0.97, 'distance_m' => 4000, 'weight' => 80.0, 'sleep_start' => strtotime($day . ' 00:10:00 Europe/Berlin')];
			$rows[] = $patch ? $patch($i, $r) : $r;
		}
		return $rows;
	}

	private function wo(string $day, string $act = 'Cycling', float $min = 60, ?int $m = 20000): array {
		return ['day' => $day, 'activity' => $act, 'duration_min' => $min, 'distance_m' => $m, 'kcal' => 300, 'start_ts' => strtotime($day . ' 17:00 UTC')];
	}

	public function testGoalsSanitized(): void {
		$g = Agent::goals(['steps' => '100', 'sleep_min' => 9999, 'weight' => 'x', 'workouts_week' => 4]);
		$this->assertSame(1000, $g['steps']);
		$this->assertSame(720, $g['sleep_min']);
		$this->assertNull($g['weight']);
		$this->assertSame(4, $g['workouts_week']);
		$this->assertSame(Agent::DEFAULT_GOALS, Agent::goals(null));
	}

	public function testAdaptiveTargetsMoveTowardsGoal(): void {
		$t = Agent::weeklyTargets(Agent::goals([]), $this->rows(), [$this->wo('2026-03-20')], '2026-03-30');
		$this->assertSame(6600, $t['steps'], '+10 %');
		$this->assertSame(415, $t['sleep_min'], '+15 min');
		$this->assertSame(330, $t['active_kcal']);
		$this->assertSame(1, $t['workouts'], '0.25 per week -> 1');
		$high = Agent::weeklyTargets(Agent::goals(['steps' => 5000]), $this->rows(), [], '2026-03-30');
		$this->assertSame(5000, $high['steps'], 'never above the goal');
	}

	public function testProgressAndStreak(): void {
		$rows = $this->rows(60, '2026-03-31', fn ($i, $r) => $i >= 50 ? ['steps' => 9000] + $r : $r);
		$p = Agent::progress(Agent::goals([]), $rows, [$this->wo('2026-03-31')], '2026-03-31');
		$this->assertSame('2026-03-30', $p['week_start']);
		$this->assertSame(2, $p['days_in_week']);
		$this->assertSame(1, $p['workouts']['done']);
		$this->assertSame(['current' => 10, 'best' => 10], Agent::streak($rows, 'steps', 8000));
		$gap = $rows;
		unset($gap[55]);
		$this->assertSame(['current' => 4, 'best' => 5], Agent::streak(array_values($gap), 'steps', 8000), 'a missing day breaks the streak');
	}

	public function testPlanEvaluation(): void {
		$plan = Agent::sanitizePlan(['days' => [['date' => '2026-03-29', 'kind' => 'train', 'note' => 'x'], ['date' => '2026-03-30', 'kind' => 'rest'],
			['date' => '2026-03-31', 'kind' => 'TRAIN'], ['date' => 'bad', 'kind' => 'train'], ['date' => '2026-04-01', 'kind' => 'party']], 'bedtime' => '23:30']);
		$this->assertCount(3, $plan['days']);
		$e = Agent::evaluatePlan($plan, $this->rows(), [$this->wo('2026-03-29'), $this->wo('2026-03-30', 'Walking', 45, 3000)]);
		$this->assertSame(3, $e['days_evaluated']);
		$this->assertSame(1, $e['days_met'], 'trained on 29, but walked 45 min on rest day 30, no training 31');
		$this->assertSame(33, $e['adherence_pct']);
		$this->assertSame(['target' => '23:30', 'nights' => 3, 'on_time' => 0], $e['bedtime'], '00:10 is 40 min after 23:30');
		$e2 = Agent::evaluatePlan(['days' => $plan['days'], 'bedtime' => '23:45'], $this->rows(), []);
		$this->assertSame(3, $e2['bedtime']['on_time'], '00:10 is within 30 min after 23:45');
		$this->assertNull(Agent::evaluatePlan(null, [], []));
		$this->assertNull(Agent::sanitizePlan('nope'));
	}

	public function testNudges(): void {
		$rows = $this->rows(60, '2026-03-31', fn ($i, $r) => $i >= 58 ? ['resting_hr' => 63.0] + $r : $r);
		$this->assertContains('rest_day', array_column(Agent::nudges($rows, Agent::goals([])), 'key'));
		$rows = $this->rows(60, '2026-03-31', fn ($i, $r) => $i >= 46 ? ['steps' => 9000] + $r : $r);
		$n = Agent::nudges($rows, Agent::goals([]));
		$this->assertSame([['key' => 'streak_steps', 'params' => ['days' => 14]]], $n);
		$this->assertSame([], Agent::nudges([], Agent::goals([])));
	}

	public function testInfographic(): void {
		$rows = $this->rows(400, '2026-03-31');
		$wo = [$this->wo('2026-03-28'), $this->wo('2026-03-30', 'Running', 30, 6000), $this->wo('2025-06-01', 'Cycling', 120, 55000)];
		$g = Infographic::build($rows, $wo, Agent::goals([]), 'week');
		$this->assertSame('2026-03-25', $g['period']['from']);
		$this->assertSame(7, $g['current']['days_with_data']);
		$this->assertSame(400.0, (float)$g['current']['sleep_avg']);
		$this->assertSame(0.15, $g['current']['deep_share']);
		$this->assertSame(97.0, (float)$g['current']['spo2']);
		$this->assertSame(42000, $g['current']['steps_total']);
		$this->assertSame(2, $g['current']['workouts']);
		$this->assertSame(['activity' => 'Cycling', 'count' => 1, 'hours' => 1.0, 'km' => 20.0, 'kmh' => 20.0, 'kcal' => 300], $g['sports'][0]);
		$this->assertSame(12.0, $g['sports'][1]['kmh']);
		$this->assertSame(55.0, $g['records']['longest_ride']['km']);
		$this->assertSame('2025-06-01', $g['records']['longest_ride']['day']);
		$this->assertSame(['score' => 0, 'days_met' => 0, 'days' => 7], $g['consistency'], 'no goal met');
		$this->assertCount(35, $g['heatmap']);
		$this->assertSame(['value' => 80.0, 'day' => '2026-03-31'], $g['latest']['weight']);
		$this->assertNull($g['latest']['vo2max']);
		$this->assertSame(2, $g['heatmap'][34]['level'], '6000 steps = 6 points');
		$this->assertSame(3, $g['heatmap'][33]['level'], '6000 steps + 30 min = 9 points');
		$this->assertSame(3, $g['heatmap'][31]['level'], '6000 steps + 60 min = 12 points');
		$this->assertSame('2026-03-28', $g['heatmap'][31]['day']);
	}

	public function testChat(): void {
		$c = Chat::context($this->rows(400), [$this->wo('2026-03-28')], Agent::goals([]), [['period_to' => '2026-03-22', 'summary' => 'x']]);
		$this->assertSame(31, $c['monthly']['2026-03']['days']);
		$this->assertSame(400.0, (float)$c['monthly']['2026-03']['sleep_total']);
		$this->assertSame(1, $c['monthly']['2026-03']['workouts']);
		$this->assertCount(12, $c['weekly']);
		$this->assertFalse(str_contains(json_encode($c), 'sleep_start'), 'no raw timestamps');
		$m = Chat::messages("Wie war mein Schlaf?\x07", $c, 'de');
		$this->assertStringContainsString('if unclear: German', $m[0]['content']);
		$this->assertStringContainsString('Question: Wie war mein Schlaf?', $m[1]['content']);
		$this->assertSame(500, mb_strlen(Chat::cleanQuestion(str_repeat('ä', 900))));
		$now = 100000;
		$this->assertTrue(Chat::allowed(array_fill(0, 9, $now - 10), $now));
		$this->assertFalse(Chat::allowed(array_fill(0, 10, $now - 10), $now));
		$this->assertSame('Hallo', Chat::answerOf(['choices' => [['message' => ['content' => '<think>hm</think> Hallo']]]]));
		$this->assertSame('', Chat::answerOf([]));
	}
}
