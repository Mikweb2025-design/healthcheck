<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Controller;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Service\Calendar;
use OCA\HealthCheck\Service\DataService;
use OCA\HealthCheck\Service\ImportService;
use OCA\HealthCheck\Service\Outbound;
use OCA\HealthCheck\Service\Settings;
use OCA\HealthCheck\Service\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * JSON API of the app. Every endpoint works on the data of the logged-in user only (also for administrators).
 * The two automation endpoints accept the same login (an app password of the user via Basic auth).
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private DataService $data,
		private ImportService $import,
		private Settings $settings,
		private Store $store,
		private Calendar $calendar,
		private Outbound $outbound,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		$u = $this->session->getUser();
		if ($u === null) {
			throw new \DomainException('unauthorized');
		}
		return $u->getUID();
	}

	private function run(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn($this->uid()));
		} catch (\DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getMessage() === 'unauthorized' ? Http::STATUS_UNAUTHORIZED : Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => 'validation', 'fields' => [$e->getMessage()]], Http::STATUS_BAD_REQUEST);
		} catch (\OverflowException $e) {
			return new JSONResponse(['error' => 'rate_limited'], Http::STATUS_TOO_MANY_REQUESTS);
		} catch (\Throwable $e) {
			$this->logger->error('Health Check: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$code = $e instanceof \RuntimeException && preg_match('/^[a-z_]+$/', $e->getMessage()) ? $e->getMessage() : 'server_error';
			return new JSONResponse(['error' => $code], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private static function day(?string $d): ?string {
		return ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
	}

	#[NoAdminRequired]
	public function overview(): JSONResponse {
		return $this->run(fn ($uid) => $this->data->overview($uid));
	}

	#[NoAdminRequired]
	public function series(?string $from = null, ?string $to = null): JSONResponse {
		return $this->run(fn ($uid) => $this->data->series($uid, self::day($from), self::day($to)));
	}

	#[NoAdminRequired]
	public function report(int $days = 30): JSONResponse {
		return $this->run(fn ($uid) => $this->data->report($uid, $days));
	}

	#[NoAdminRequired]
	public function infographic(string $period = 'week'): JSONResponse {
		return $this->run(fn ($uid) => $this->data->infographic($uid, $period));
	}

	#[NoAdminRequired]
	public function status(): JSONResponse {
		return $this->run(fn ($uid) => $this->data->status($uid) + ['imports' => $this->store->imports($uid, 10)]);
	}

	/** runs the import now (can take a minute; continues on the server when the browser gives up) */
	#[NoAdminRequired]
	public function importNow(): JSONResponse {
		return $this->run(function ($uid) {
			ignore_user_abort(true);
			$this->settings->activate($uid);
			return $this->import->run($uid, 'manual');
		});
	}

	#[NoAdminRequired]
	public function settings(): JSONResponse {
		return $this->run(fn ($uid) => $this->settings->forUser($uid));
	}

	#[NoAdminRequired]
	public function updateSettings(): JSONResponse {
		return $this->run(function ($uid) {
			$before = $this->settings->lang($uid);
			$bad = $this->settings->update($uid, $this->request->getParams());
			if ($bad !== []) {
				throw new \InvalidArgumentException(implode(',', $bad));
			}
			// calendar titles follow the app language: rewrite the events whose rendered text changed
			if ($before !== $this->settings->lang($uid) && $this->settings->get($uid, 'calendar_sync', '1') === '1') {
				try {
					$this->calendar->sync($uid, $this->store->workouts($uid), $this->store->days($uid),
						$this->settings->get($uid, 'calendar_daily', '0') === '1', $this->settings->lang($uid));
				} catch (\Throwable $e) {
					$this->logger->warning('Health Check calendar resync failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
			return $this->settings->forUser($uid);
		});
	}

	#[NoAdminRequired]
	public function newSecret(): JSONResponse {
		return $this->run(fn ($uid) => ['secret' => $this->settings->newSecret($uid)]);
	}

	#[NoAdminRequired]
	public function calendarSync(): JSONResponse {
		return $this->run(function ($uid) {
			$this->settings->set($uid, 'calendar_sync', '1');
			return $this->calendar->sync($uid, $this->store->workouts($uid), $this->store->days($uid),
				$this->settings->get($uid, 'calendar_daily', '0') === '1', $this->settings->lang($uid));
		});
	}

	#[NoAdminRequired]
	public function calendarRemove(): JSONResponse {
		return $this->run(function ($uid) {
			$this->settings->set($uid, 'calendar_sync', '0');
			return ['removed' => $this->calendar->remove($uid)];
		});
	}

	#[NoAdminRequired]
	public function requestReport(): JSONResponse {
		return $this->run(function ($uid) {
			$r = $this->outbound->webhook($uid, 'manual');
			if (!$r['ok']) {
				throw new \DomainException($r['error'] ?? 'webhook_failed');
			}
			return $r;
		});
	}

	#[NoAdminRequired]
	public function reports(): JSONResponse {
		return $this->run(fn ($uid) => ['reports' => $this->store->aiReports($uid, 12)]);
	}

	#[NoAdminRequired]
	public function chats(): JSONResponse {
		return $this->run(fn ($uid) => ['chats' => $this->store->chats($uid, 20), 'available' => $this->settings->aiConfigured()]);
	}

	#[NoAdminRequired]
	public function ask(string $question = ''): JSONResponse {
		return $this->run(fn ($uid) => $this->data->ask($uid, $question));
	}

	#[NoAdminRequired]
	public function weights(): JSONResponse {
		return $this->run(fn ($uid) => ['entries' => array_reverse($this->store->weights($uid))]);
	}

	#[NoAdminRequired]
	public function addWeight(): JSONResponse {
		return $this->run(fn ($uid) => $this->data->addWeight($uid, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function deleteWeight(int $id): JSONResponse {
		return $this->run(fn ($uid) => $this->data->deleteWeight($uid, $id));
	}

	#[NoAdminRequired]
	public function aiTask(string $kind): JSONResponse {
		return $this->run(fn ($uid) => $this->data->aiTask($uid, $kind, $this->request->getParams()));
	}

	#[NoAdminRequired]
	public function deleteAll(bool $calendar = true): JSONResponse {
		return $this->run(fn ($uid) => $this->data->deleteAll($uid, $calendar));
	}

	// ---- automation (n8n): Basic auth with an app password of the same user ----------------------------------
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function automationSummary(): JSONResponse {
		return $this->run(fn ($uid) => $this->data->automationSummary($uid));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function automationReport(): JSONResponse {
		return $this->run(fn ($uid) => $this->data->saveAutomationReport($uid, $this->request->getParams()));
	}
}
