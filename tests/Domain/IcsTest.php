<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Ics;
use OCA\HealthCheck\Domain\Manifest;
use OCA\HealthCheck\Domain\Messages;
use OCA\HealthCheck\Domain\Signature;
use OCA\HealthCheck\Domain\StandalonePage;
use OCA\HealthCheck\Tests\TestCase;

class IcsTest extends TestCase {
	private function workout(): array {
		return ['wkey' => str_repeat('a', 40), 'activity' => 'Cycling', 'source' => 'Watch', 'start_ts' => 1773068400, 'end_ts' => 1773072720,
			'duration_min' => 72, 'distance_m' => 32400, 'kcal' => 512, 'hr_avg' => 128.4];
	}

	public function testWorkoutEvent(): void {
		$this->assertSame('🚴 Radfahren · 32,4 km · 1:12 h', Ics::workoutTitle($this->workout(), 'de'));
		$this->assertSame('🚴 Cycling · 32.4 km · 1:12 h', Ics::workoutTitle($this->workout(), 'en'));
		$ics = Ics::workout($this->workout(), 'de');
		$this->assertStringContainsString("UID:hc-w-" . str_repeat('a', 40) . "@healthcheck\r\n", $ics);
		$this->assertStringContainsString("DTSTART:20260309T150000Z\r\n", $ics);
		$this->assertStringContainsString("DTEND:20260309T161200Z\r\n", $ics);
		$this->assertStringContainsString('Ø Puls: 128 bpm', str_replace("\r\n ", '', $ics));
		$this->assertSame($ics, Ics::workout($this->workout(), 'de'), 'deterministic');
		foreach (explode("\r\n", $ics) as $line) {
			$this->assertTrue(strlen($line) <= 75, 'folded: ' . $line);
		}
		$this->assertSame('hc-w-' . str_repeat('a', 40) . '.ics', Ics::workoutUri($this->workout()));
	}

	public function testDayEvent(): void {
		$row = ['day' => '2026-03-09', 'sleep_total' => 432, 'resting_hr' => 54.4, 'steps' => 9420];
		$this->assertSame('💤 7 h 12 · ❤️ 54 · 👣 9.420', Ics::dayTitle($row, 'de'));
		$this->assertNull(Ics::day(['day' => '2026-03-09'], 'de'));
		$ics = (string)Ics::day($row, 'it');
		$this->assertStringContainsString("DTSTART;VALUE=DATE:20260309\r\nDTEND;VALUE=DATE:20260310", $ics);
	}

	public function testMessages(): void {
		$this->assertSame('en', Messages::lang('fr'));
		$this->assertSame('it', Messages::lang('it_IT'));
		$this->assertSame(['🏅', 'Paddle Sports'], Messages::activity('PaddleSports', 'de'));
		$this->assertSame('45 min', Messages::dur(45));
		$this->assertSame('09.03.2026', Messages::day('2026-03-09', 'it'));
	}

	public function testSignature(): void {
		$body = ['ts' => 1000, 'event' => 'import', 'user' => 'u1', 'nonce' => 'n'];
		$sig = Signature::sign('s3cret', 1000, 'import', 'u1', 'n');
		$this->assertSame('sha256=' . hash_hmac('sha256', '1000.import.u1.n.', 's3cret'), $sig);
		$this->assertTrue(Signature::verify('s3cret', $body, $sig, 1100));
		$this->assertFalse(Signature::verify('s3cret', $body, $sig, 2000), 'too old');
		$this->assertFalse(Signature::verify('other', $body, $sig, 1100));
		$this->assertFalse(Signature::verify('', $body, $sig, 1100));
		$this->assertFalse(Signature::verify('s3cret', $body + ['payload' => 'x'], $sig, 1100), 'payload is signed');
		$this->assertTrue(Signature::validUrl('https://example.org/webhook/x'));
		$this->assertFalse(Signature::validUrl('http://example.org/webhook/x'));
		$this->assertFalse(Signature::validUrl('https://a:b@example.org/'));
	}

	public function testManifestAndPage(): void {
		$m = Manifest::build('/apps/healthcheck/', ['192' => '/i/192.png', '512' => '/i/512.png', 'maskable' => '/i/m.png', 'svg' => '/i/a.svg'], 'de');
		$this->assertSame('/apps/healthcheck/', $m['start_url']);
		$this->assertSame('/apps/healthcheck/', $m['scope']);
		$this->assertSame('standalone', $m['display']);
		$html = StandalonePage::html(['lang' => 'de', 'title' => 'Gesundheits-Check', 'base' => '/apps/healthcheck', 'manifest' => '/apps/healthcheck/manifest',
			'icon' => '/i/192.png', 'apple' => '/i/apple.png', 'css' => ['/c.css'], 'js' => ['/a.js'], 'page' => 'main', 'requesttoken' => 'x"y']);
		$this->assertStringContainsString('<link rel="manifest" href="/apps/healthcheck/manifest">', $html);
		$this->assertStringContainsString('apple-touch-icon', $html);
		$this->assertStringContainsString('data-requesttoken="x&quot;y"', $html);
		$this->assertStringNotContainsString('<script>', $html, 'no inline script');
		$this->assertStringContainsString("script-src 'self'", StandalonePage::csp());
	}
}
