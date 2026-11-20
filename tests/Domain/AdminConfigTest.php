<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\AdminConfig;
use OCA\HealthCheck\Domain\Chat;
use OCA\HealthCheck\Tests\TestCase;

class AdminConfigTest extends TestCase {
	public function testValidateAcceptsGoodValues(): void {
		$r = AdminConfig::validate(['ai_enabled' => '0', 'ai_provider' => 'ollama', 'ai_url' => 'http://localhost:11434/v1/chat/completions', 'ai_model' => 'llama3.1',
			'ai_per_hour' => '5', 'report_mode' => 'builtin', 'default_lang' => 'it', 'allowed_groups' => "staff, health\nstaff"]);
		$this->assertSame([], $r['bad']);
		$this->assertSame(false, $r['clean']['ai_enabled']);
		$this->assertSame(5, $r['clean']['ai_per_hour']);
		$this->assertSame(['staff', 'health'], $r['clean']['allowed_groups']);
		$this->assertSame('builtin', $r['clean']['report_mode']);
	}

	public function testValidateRejectsBadValues(): void {
		$r = AdminConfig::validate(['ai_provider' => 'x', 'ai_url' => 'ftp://a', 'ai_model' => 'a b', 'ai_per_day' => 0, 'ai_max_tokens' => 'abc', 'report_mode' => 'cron', 'default_lang' => 'fr', 'ai_timeout' => 5]);
		sort($r['bad']);
		$this->assertSame(['ai_max_tokens', 'ai_model', 'ai_per_day', 'ai_provider', 'ai_timeout', 'ai_url', 'default_lang', 'report_mode'], $r['bad']);
		$this->assertSame([], $r['clean']);
		$this->assertFalse(AdminConfig::aiUrl('https://user:pw@host/v1'));
		$this->assertTrue(AdminConfig::aiUrl('http://192.168.1.5:11434/v1/chat/completions'));
	}

	public function testOnlySentKeysAreReturned(): void {
		$this->assertSame([], AdminConfig::validate([])['clean']);
		$this->assertSame(['ai_chat' => false], AdminConfig::validate(['ai_chat' => 'false'])['clean']);
	}

	public function testAiReady(): void {
		$this->assertTrue(AdminConfig::aiReady(true, 'ionos', 'https://x', 'm', true));
		$this->assertFalse(AdminConfig::aiReady(true, 'ionos', 'https://x', 'm', false));
		$this->assertTrue(AdminConfig::aiReady(true, 'ollama', 'http://localhost', 'm', false));
		$this->assertFalse(AdminConfig::aiReady(false, 'ollama', 'http://localhost', 'm', false));
		$this->assertFalse(AdminConfig::aiReady(true, 'custom', '', 'm', true));
	}

	public function testGroupAllowed(): void {
		$this->assertTrue(AdminConfig::groupAllowed([], ['a']));
		$this->assertTrue(AdminConfig::groupAllowed(['a', 'b'], ['b', 'c']));
		$this->assertFalse(AdminConfig::groupAllowed(['a'], ['b']));
		$this->assertFalse(AdminConfig::groupAllowed(['a'], []));
	}

	public function testWeeklyDue(): void {
		$mon = new \DateTimeImmutable('2026-11-23 07:00:00', new \DateTimeZone('Europe/Berlin'));
		$this->assertTrue(AdminConfig::weeklyDue($mon, 0, '2026-11-22'));
		$this->assertFalse(AdminConfig::weeklyDue($mon->modify('05:00'), 0, '2026-11-22'), 'before 06:00');
		$this->assertFalse(AdminConfig::weeklyDue($mon->modify('+1 day'), 0, '2026-11-22'), 'not Monday');
		$this->assertFalse(AdminConfig::weeklyDue($mon, $mon->getTimestamp() - 3600, '2026-11-22'), 'already done today');
		$this->assertFalse(AdminConfig::weeklyDue($mon, 0, '2026-11-01'), 'no fresh data');
		$this->assertFalse(AdminConfig::weeklyDue($mon, 0, null));
	}

	public function testReportFromCheckup(): void {
		$r = AdminConfig::reportFromCheckup(['good' => ['Sleep steady.'], 'watch' => ['Few steps.'], 'actions' => ['Walk more.'], 'doctor' => 'Mention sleep.']);
		$this->assertSame('Sleep steady. Few steps.', $r['summary']);
		$this->assertSame(['Walk more.'], $r['tips']);
	}

	public function testChatLimitsAreConfigurable(): void {
		$now = 100000;
		$times = array_fill(0, 3, $now - 60);
		$this->assertTrue(Chat::allowed($times, $now));
		$this->assertFalse(Chat::allowed($times, $now, 3, 40));
		$this->assertFalse(Chat::allowed($times, $now, 10, 3));
	}
}
