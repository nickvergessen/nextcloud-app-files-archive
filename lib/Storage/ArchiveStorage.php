<?php
/**
 * @author    Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine
 * @license   AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\FilesArchive\Storage;

use wapmorgan\UnifiedArchive\ArchiveEntry;

use Psr\Log\LoggerInterface;

// F I X M E: those are not public, but ...
use OC\Files\Storage\Common as AbstractStorage;
use OC\Files\Storage\PolyFill\CopyDirectory;

use Icewind\Streams\CallbackWrapper;
use Icewind\Streams\CountWrapper;
use Icewind\Streams\IteratorDirectory;

use OCP\AppFramework\IAppContainer;

use OCP\Files\FileInfo;
use OCP\Files\File;

use OCA\FilesArchive\Service\ArchiveService;
use OCA\FilesArchive\Exceptions;
use OCA\FilesArchive\Constants;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/** Virtual storage mapping an archive file into the user file-space. */
class ArchiveStorage extends AbstractStorage
{
  use \OCA\FilesArchive\Traits\LoggerTrait;
  use \OCA\FilesArchive\Traits\UtilTrait;
  use CopyDirectory;

  public const PATH_SEPARATOR = Constants::PATH_SEPARATOR;

  /** @var string */
  protected $appName;

  /** @var IAppContainer */
  protected $appContainer;

  /** @var ArchiveService */
  protected $archiveService;

  /** @var File */
  protected $archiveFile;

  /** @var array */
  protected $dirNames = [];

  /** @var array<string, ArchiveEntry> */
  protected $files = [];

  /** {@inheritdoc} */
  public function __construct($parameters)
  {
    parent::__construct($parameters);
    $this->archiveFile = $parameters['archiveFile'];
    $this->appContainer = $parameters['appContainer'];
    $this->appName = $this->appContainer->get('appName');
    $this->archiveService = $this->appContainer->get(ArchiveService::class);
    $this->logger = $this->appContainer->get(LoggerInterface::class);

    try {
      $this->archiveService->open($this->archiveFile);
      $files = $this->archiveService->getFiles();
      $this->files = [];
      $this->dirNames = [];
      foreach ($files as $path => $fileInfo) {
        $normalizedPath = trim($this->buildPath($path), Constants::PATH_SEPARATOR);
        $this->files[$normalizedPath] = $fileInfo;
        $dirName = dirname($normalizedPath);
        if (!empty($dirName)) {
          $pathChain = explode(Constants::PATH_SEPARATOR, dirname($normalizedPath));
          $dirPath = array_shift($pathChain);
          $this->dirNames[$dirPath] = true;
          foreach ($pathChain as $pathComponent) {
            $dirPath .= Constants::PATH_SEPARATOR . $pathComponent;
            $this->dirNames[$dirPath] = true;
          }
        }
      }
      $this->dirNames = array_keys($this->dirNames);

      // $this->logInfo('FILES ' . print_r($this->files, true));
      // $this->logInfo('DIRS ' . print_r($this->dirNames, true));

    } catch (Throwable $t) {
      $this->files = [];
      $this->dirNames = [];
    }
  }

  /** {@inheritdoc} */
  public function getId()
  {
    return $this->appName . ':' . $this->archiveFile->getPath() . self::PATH_SEPARATOR;
  }

  /**
   * @param null|string $path The path to work on.
   *
   * @return string
   */
  protected function buildPath(?string $path):string
  {
    return \OC\Files\Filesystem::normalizePath($path);
  }

  /**
   * Attach self::PATH_SEPARATOR to the dirname if it is not the root directory.
   *
   * @param string $dirName The directory name to work on.
   *
   * @return string
   */
  protected static function normalizeDirectoryName(string $dirName):string
  {
    if ($dirName == '.') {
      $dirName = '';
    }
    $dirName = trim($dirName, self::PATH_SEPARATOR);
    return empty($dirName) ? $dirName : $dirName . self::PATH_SEPARATOR;
  }

  /**
   * Slightly modified pathinfo() function which also normalized directories
   * before computing the components.
   *
   * @param string $path The path to work on.
   *
   * @param int $flags As for the upstream pathinfo() function.
   *
   * @return string|array
   */
  protected static function pathInfo(string $path, int $flags = PATHINFO_ALL)
  {
    $pathInfo = pathinfo($path, $flags);
    if ($flags == PATHINFO_DIRNAME) {
      $pathInfo = self::normalizeDirectoryName($pathInfo);
    } elseif (is_array($pathInfo)) {
      $pathInfo['dirname'] = self::normalizeDirectoryName($pathInfo['dirname']);
    }
    return $pathInfo;
  }

  /** {@inheritdoc} */
  public static function checkDependencies()
  {
    return true;
  }

  /** {@inheritdoc} */
  public function isReadable($path)
  {
    // at least check whether it exists
    // subclasses might want to implement this more thoroughly
    return $this->file_exists($path);
  }

  /** {@inheritdoc} */
  public function isUpdatable($path)
  {
    // return $this->file_exists($path);
    return false; // readonly for now
  }

  /** {@inheritdoc} */
  public function isSharable($path)
  {
    // sharing cannot work in general as the database access need additional
    // credentials
    return false;
  }

  /** {@inheritdoc} */
  public function filemtime($path)
  {
    $path = trim($path, self::PATH_SEPARATOR);
    // $this->logInfo('PATH ' . $path);
    if ($this->is_dir($path)) {
      return $this->archiveFile->getMTime();
    } elseif ($this->is_file($path)) {
      return $this->files[$path]->modificationTime;
    }
    return false;
  }

  /**
   * {@inheritdoc}
   *
   * The AbstractStorage class relies on mtime($path) > $time for triggering a
   * cache invalidation. This, however, does not cover cases where a directory
   * has been removed. Hence we also return true if mtime returns false
   * meaning that the file does not exist.
   */
  public function hasUpdated($path, $time)
  {
    $mtime = $this->filemtime($path);
    return $mtime === false || ($mtime > $time);
  }

  /** {@inheritdoc} */
  public function filesize($path)
  {
    $path = trim($path, self::PATH_SEPARATOR);
    // $this->logInfo('PATH ' . $path);
    if ($this->is_dir($path)) {
      return 0;
    }
    if (!$this->is_file($path)) {
      return false;
    }
    return $this->files[$path]->uncompressedSize;
  }

  /** {@inheritdoc} */
  public function rmdir($path)
  {
    return false;
  }

  /** {@inheritdoc} */
  public function test()
  {
    return $this->archiveService->canOpen($this->archiveFile);
  }

  /** {@inheritdoc} */
  public function stat($path)
  {
    if (!$this->is_file($path) && !$this->is_dir($path)) {
      return false;
    }
    return [
      'mtime' => $this->filemtime($path),
      'size' => $this->filesize($path),
    ];
  }

  /** {@inheritdoc} */
  public function file_exists($path)
  {
    return $this->is_dir($path) || $this->is_file($path);
  }

  /** {@inheritdoc} */
  public function unlink($path)
  {
    return false;
  }

  /** {@inheritdoc} */
  public function opendir($path)
  {
    if (!$this->is_dir($path)) {
      return false;
    }

    $path = ltrim($path, Constants::PATH_SEPARATOR);

    $fileNames = array_map(
      function(string $memberPath) use ($path) {
        $memberPath = trim(str_replace($path, '', $memberPath), Constants::PATH_SEPARATOR);
        $slashPos = strpos($memberPath, Constants::PATH_SEPARATOR);
        if ($slashPos === false) {
          return $memberPath;
        }
        return substr($memberPath, 0, $slashPos);
      },
      array_filter(
        array_keys($this->files),
        fn(string $memberPath) => str_starts_with($memberPath, $path),
      )
    );
    $fileNames = array_unique($fileNames);

    // $this->logInfo('DIRLISTING ' . $path . ': ' . print_r($fileNames, true));

    return IteratorDirectory::wrap(array_values($fileNames));
  }

  /** {@inheritdoc} */
  public function mkdir($path)
  {
    return false;
  }

  /** {@inheritdoc} */
  public function is_dir($path)
  {
    $path = trim($path, self::PATH_SEPARATOR);
    if ($path === '') {
      return true;
    }
    $result = array_search($path, $this->dirNames) !== false;

    // $this->logInfo('PATH ' . $path . '  ' . (int)$result);

    return $result;
  }

  /** {@inheritdoc} */
  public function is_file($path)
  {
    $path = trim($path, self::PATH_SEPARATOR);
    // $this->logInfo('PATH ' . $path);
    return !empty($this->files[$path]);
  }

  /** {@inheritdoc} */
  public function filetype($path)
  {
    if ($this->is_dir($path)) {
      return FileInfo::TYPE_FOLDER;
    } elseif ($this->is_file($path)) {
      return FileInfo::TYPE_FILE;
    } else {
      return false;
    }
  }

  /** {@inheritdoc} */
  public function fopen($path, $mode)
  {
    $useExisting = true;
    switch ($mode) {
      case 'r':
      case 'rb':
        return $this->readStream($path);
      case 'w':
      case 'w+':
      case 'wb':
      case 'wb+':
        $useExisting = false;
        // no break
      case 'a':
      case 'ab':
      case 'r+':
      case 'a+':
      case 'x':
      case 'x+':
      case 'c':
      case 'c+':
        //emulate these
        if ($useExisting and $this->file_exists($path)) {
          if (!$this->isUpdatable($path)) {
            return false;
          }
          $tmpFile = $this->getCachedFile($path);
        } else {
          if (!$this->isCreatable(dirname($path))) {
            return false;
          }
          if (!$this->touch($path)) {
            return false;
          }
          $tmpFile = $this->di(ITempManager::class)->getTemporaryFile();
        }
        $source = fopen($tmpFile, $mode);

        return CallbackWrapper::wrap($source, null, null, function () use ($tmpFile, $path) {
          $this->writeStream($path, fopen($tmpFile, 'r'));
          unlink($tmpFile);
        });
    }
    return false;
  }

  /** {@inheritdoc} */
  public function writeStream(string $path, $stream, int $size = null): int
  {
    return 0;
  }

  /** {@inheritdoc} */
  public function readStream(string $path)
  {
    if (!$this->is_file($path)) {
      return false;
    }
    $path = trim($path, self::PATH_SEPARATOR);

    return $this->archiveService->getFileStream($path);
  }

  /** {@inheritdoc} */
  public function touch($path, $mtime = null)
  {
    return false;
  }

  /** {@inheritdoc} */
  public function rename($path1, $path2)
  {
    return false;
  }
}
