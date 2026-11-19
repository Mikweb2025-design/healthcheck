<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/** The user's folders: /Health/Import (drop zone), /Health/Import/done (last imported zip), /Health/Reports. */
class Files {
	public const IMPORT = 'Health/Import';
	public const DONE = 'Health/Import/done';
	public const REPORTS = 'Health/Reports';

	public function __construct(private IRootFolder $root) {
	}

	private function home(string $uid): Folder {
		return $this->root->getUserFolder($uid);
	}

	private function folder(string $uid, string $path): Folder {
		$home = $this->home($uid);
		$cur = $home;
		foreach (explode('/', $path) as $part) {
			try {
				$n = $cur->get($part);
				if (!$n instanceof Folder) {
					throw new \RuntimeException('not_a_folder');
				}
				$cur = $n;
			} catch (NotFoundException) {
				$cur = $cur->newFolder($part);
			}
		}
		return $cur;
	}

	public function ensure(string $uid): void {
		$this->folder($uid, self::IMPORT);
		$this->folder($uid, self::DONE);
		$this->folder($uid, self::REPORTS);
	}

	/** zip files waiting in /Health/Import, newest first @return list<File> */
	public function pending(string $uid): array {
		try {
			$f = $this->home($uid)->get(self::IMPORT);
		} catch (NotFoundException) {
			return [];
		}
		if (!$f instanceof Folder) {
			return [];
		}
		$out = [];
		foreach ($f->getDirectoryListing() as $n) {
			if ($n instanceof File && str_ends_with(strtolower($n->getName()), '.zip')) {
				$out[] = $n;
			}
		}
		usort($out, fn (File $a, File $b) => $b->getMTime() <=> $a->getMTime());
		return $out;
	}

	public function pendingInfo(string $uid): array {
		return array_map(fn (File $f) => ['name' => $f->getName(), 'size' => $f->getSize(), 'mtime' => $f->getMTime()], $this->pending($uid));
	}

	/** a local path the parser can open (the storage makes a temporary copy if the file is not on local disk) */
	public function localPath(File $f): string {
		$p = $f->getStorage()->getLocalFile($f->getInternalPath());
		if (!is_string($p) || $p === '' || !is_file($p)) {
			throw new \RuntimeException('no_local_file');
		}
		return $p;
	}

	/** moves the imported zip into done/ and removes older zips there (only the latest is kept) */
	public function archive(string $uid, File $f): void {
		$done = $this->folder($uid, self::DONE);
		foreach ($done->getDirectoryListing() as $n) {
			if ($n instanceof File && str_ends_with(strtolower($n->getName()), '.zip')) {
				$n->delete();
			}
		}
		$name = $f->getName();
		$f->move($done->getPath() . '/' . $name);
		// other zips still waiting are older exports of the same data: they are superseded
		foreach ($this->pending($uid) as $old) {
			if ($old->getMTime() <= $f->getMTime()) {
				$old->delete();
			}
		}
	}
}
