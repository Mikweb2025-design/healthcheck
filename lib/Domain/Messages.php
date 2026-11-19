<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/** Server side texts (notifications, dashboard widget, calendar). The app UI has its own dictionary in js/hc-i18n.js. */
final class Messages {
	public const LANGS = ['de', 'en', 'it'];

	public static function lang(?string $l): string {
		$l = strtolower(substr((string)$l, 0, 2));
		return in_array($l, self::LANGS, true) ? $l : 'en';
	}

	private const T = [
		'app' => ['de' => 'Gesundheits-Check', 'en' => 'Health Check', 'it' => 'Health Check'],
		'calendar' => ['de' => 'Sport & Gesundheit', 'en' => 'Sport & Gesundheit', 'it' => 'Sport & Salute'],
		'n_stale_t' => ['de' => 'Bitte neuen Export hochladen', 'en' => 'Please upload a new export', 'it' => 'Carica una nuova esportazione'],
		'n_stale_m' => ['de' => 'Die letzten Gesundheitsdaten sind vom {day} ({days} Tage alt). Export aus der Health-App in den Ordner /Health/Import legen.',
			'en' => 'The latest health data is from {day} ({days} days old). Put an export from the Health app into the folder /Health/Import.',
			'it' => 'Gli ultimi dati sono del {day} ({days} giorni fa). Metti un’esportazione dell’app Salute nella cartella /Health/Import.'],
		'n_report_t' => ['de' => 'Neuer Wochenbericht', 'en' => 'New weekly report', 'it' => 'Nuovo report settimanale'],
		'n_report_m' => ['de' => 'Der KI-Wochenbericht bis {day} ist da.', 'en' => 'The AI weekly report up to {day} is ready.', 'it' => 'Il report settimanale IA fino al {day} è pronto.'],
		'n_import_t' => ['de' => 'Gesundheitsdaten importiert', 'en' => 'Health data imported', 'it' => 'Dati sanitari importati'],
		'n_import_m' => ['de' => '{days} Tage und {workouts} Trainings, letzter Tag {day}.', 'en' => '{days} days and {workouts} workouts, last day {day}.', 'it' => '{days} giorni e {workouts} allenamenti, ultimo giorno {day}.'],
		'n_error_t' => ['de' => 'Import fehlgeschlagen', 'en' => 'Import failed', 'it' => 'Importazione non riuscita'],
		'n_error_m' => ['de' => 'Die Datei {file} konnte nicht gelesen werden ({error}).', 'en' => 'The file {file} could not be read ({error}).', 'it' => 'Impossibile leggere il file {file} ({error}).'],
		'n_rest_day_t' => ['de' => 'Heute eher Ruhetag', 'en' => 'Consider a rest day', 'it' => 'Oggi meglio un giorno di riposo'],
		'n_rest_day_m' => ['de' => 'Dein Ruhepuls liegt über deinem üblichen Wert bzw. die Erholung ist niedrig. Locker bleiben, gut schlafen. Kein medizinischer Rat.',
			'en' => 'Your resting heart rate is above your usual level or recovery is low. Take it easy and sleep well. Not medical advice.',
			'it' => 'La frequenza a riposo è sopra il tuo valore abituale o il recupero è basso. Vai piano e dormi bene. Non è un consiglio medico.'],
		'n_streak_steps_t' => ['de' => '{days} Tage in Folge Schrittziel erreicht', 'en' => 'Step goal reached {days} days in a row', 'it' => 'Obiettivo passi raggiunto {days} giorni di fila'],
		'n_streak_steps_m' => ['de' => 'Starke Serie – weiter so!', 'en' => 'Great streak – keep going!', 'it' => 'Ottima serie – continua così!'],
		'n_streak_sleep_t' => ['de' => '{days} Nächte in Folge genug Schlaf', 'en' => 'Enough sleep {days} nights in a row', 'it' => 'Sonno sufficiente {days} notti di fila'],
		'n_streak_sleep_m' => ['de' => 'Dein Schlafziel hält – gute Routine.', 'en' => 'Your sleep goal holds – good routine.', 'it' => 'Il tuo obiettivo di sonno regge – buona routine.'],
		'n_weigh_in_t' => ['de' => 'Zeit fürs Wiegen', 'en' => 'Time to weigh in', 'it' => 'È ora di pesarti'],
		'n_weigh_in_m' => ['de' => 'Letzte Messung vor {days} Tagen ({last} kg). Gewicht in der App eintragen oder die Waage synchronisieren.',
			'en' => 'Last weigh-in {days} days ago ({last} kg). Enter it in the app or sync the scale.',
			'it' => 'Ultima pesata {days} giorni fa ({last} kg). Inseriscila nell’app o sincronizza la bilancia.'],
		'w_empty' => ['de' => 'Noch keine Daten. Export.zip in /Health/Import legen.', 'en' => 'No data yet. Put Export.zip into /Health/Import.', 'it' => 'Ancora nessun dato. Metti Export.zip in /Health/Import.'],
		'w_sleep' => ['de' => 'Schlaf', 'en' => 'Sleep', 'it' => 'Sonno'],
		'w_recovery' => ['de' => 'Erholung', 'en' => 'Recovery', 'it' => 'Recupero'],
		'w_movement' => ['de' => 'Bewegung', 'en' => 'Movement', 'it' => 'Movimento'],
		'w_import' => ['de' => 'Letzter Import: {day}', 'en' => 'Last import: {day}', 'it' => 'Ultima importazione: {day}'],
		'w_stale' => ['de' => 'Daten {days} Tage alt – bitte neuen Export hochladen', 'en' => 'Data {days} days old – please upload a new export', 'it' => 'Dati vecchi di {days} giorni – carica una nuova esportazione'],
		'w_open' => ['de' => 'Gesundheits-Check öffnen', 'en' => 'Open Health Check', 'it' => 'Apri Health Check'],
		'w_hint' => ['de' => '{n} Hinweis(e) – im Gesundheits-Check ansehen', 'en' => '{n} hint(s) – see Health Check', 'it' => '{n} avviso/i – vedi Health Check'],
		'c_duration' => ['de' => 'Dauer', 'en' => 'Duration', 'it' => 'Durata'],
		'c_distance' => ['de' => 'Strecke', 'en' => 'Distance', 'it' => 'Distanza'],
		'c_energy' => ['de' => 'Aktive Energie', 'en' => 'Active energy', 'it' => 'Energia attiva'],
		'c_hr' => ['de' => 'Ø Puls', 'en' => 'Avg heart rate', 'it' => 'FC media'],
		'c_source' => ['de' => 'Quelle', 'en' => 'Source', 'it' => 'Fonte'],
		'c_auto' => ['de' => 'Automatisch aus dem Gesundheits-Check importiert.', 'en' => 'Imported automatically by Health Check.', 'it' => 'Importato automaticamente da Health Check.'],
	];

	public const ACTIVITY = [
		'Running' => ['🏃', 'Laufen', 'Running', 'Corsa'],
		'Walking' => ['🚶', 'Gehen', 'Walking', 'Camminata'],
		'Hiking' => ['🥾', 'Wandern', 'Hiking', 'Escursione'],
		'Cycling' => ['🚴', 'Radfahren', 'Cycling', 'Ciclismo'],
		'Swimming' => ['🏊', 'Schwimmen', 'Swimming', 'Nuoto'],
		'TraditionalStrengthTraining' => ['🏋️', 'Krafttraining', 'Strength training', 'Allenamento forza'],
		'FunctionalStrengthTraining' => ['🏋️', 'Funktionelles Training', 'Functional training', 'Allenamento funzionale'],
		'HighIntensityIntervalTraining' => ['🔥', 'HIIT', 'HIIT', 'HIIT'],
		'Yoga' => ['🧘', 'Yoga', 'Yoga', 'Yoga'],
		'Elliptical' => ['🏃', 'Crosstrainer', 'Elliptical', 'Ellittica'],
		'Rowing' => ['🚣', 'Rudern', 'Rowing', 'Canottaggio'],
		'Skiing' => ['⛷️', 'Skifahren', 'Skiing', 'Sci'],
		'DownhillSkiing' => ['⛷️', 'Skifahren', 'Skiing', 'Sci'],
		'CrossCountrySkiing' => ['⛷️', 'Langlauf', 'Cross-country skiing', 'Sci di fondo'],
		'Soccer' => ['⚽', 'Fußball', 'Football', 'Calcio'],
		'Tennis' => ['🎾', 'Tennis', 'Tennis', 'Tennis'],
		'Dance' => ['💃', 'Tanzen', 'Dance', 'Danza'],
		'Other' => ['🏅', 'Training', 'Workout', 'Allenamento'],
	];

	/** @return array{0:string,1:string}|null title and message of a notification subject */
	public static function notification(string $subject, array $p, string $lang): ?array {
		if (!isset(self::T['n_' . $subject . '_t'])) {
			return null;
		}
		if (isset($p['day'])) {
			$p['day'] = self::day((string)$p['day'], $lang);
		}
		return [self::t('n_' . $subject . '_t', $lang, $p), self::t('n_' . $subject . '_m', $lang, $p)];
	}

	public static function t(string $key, string $lang, array $p = []): string {
		$s = self::T[$key][self::lang($lang)] ?? self::T[$key]['en'] ?? $key;
		foreach ($p as $k => $v) {
			$s = str_replace('{' . $k . '}', (string)$v, $s);
		}
		return $s;
	}

	/** @return array{0:string,1:string} emoji, localised name */
	public static function activity(string $activity, string $lang): array {
		$a = self::ACTIVITY[$activity] ?? null;
		$i = ['de' => 1, 'en' => 2, 'it' => 3][self::lang($lang)];
		if ($a === null) {
			$name = trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', $activity));
			return ['🏅', $name === '' ? self::ACTIVITY['Other'][$i] : $name];
		}
		return [$a[0], $a[$i]];
	}

	public static function num(float $v, int $dec, string $lang): string {
		return $lang === 'en' ? number_format($v, $dec, '.', ',') : number_format($v, $dec, ',', '.');
	}

	/** 72 min => "1:12 h", 45 => "45 min" */
	public static function dur(float $min): string {
		$m = (int)round($min);
		return $m < 60 ? $m . ' min' : intdiv($m, 60) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . ' h';
	}

	public static function day(string $iso, string $lang): string {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
			return $iso;
		}
		return self::lang($lang) === 'en' ? $iso : substr($iso, 8, 2) . '.' . substr($iso, 5, 2) . '.' . substr($iso, 0, 4);
	}
}
