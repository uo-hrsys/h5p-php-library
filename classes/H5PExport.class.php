<?php
/**
* This class is used for exporting zips
*/
class H5PExport {
  public $h5pF;
  public $h5pC;

  /**
   * Constructor for the H5PExport
   *
   * @param H5PFrameworkInterface|object $H5PFramework
   *  The frameworks implementation of the H5PFrameworkInterface
   * @param H5PCore $H5PCore
   *  Reference to an instance of H5PCore
   */
  public function __construct(H5PFrameworkInterface $H5PFramework, H5PCore $H5PCore) {
    $this->h5pF = $H5PFramework;
    $this->h5pC = $H5PCore;
  }

  /**
   * Return path to h5p package.
   *
   * Creates package if not already created
   *
   * @param array $content
   * @return string
   */
  public function createExportFile($content) {

    // Get path to temporary folder, where export will be contained
    $tmpPath = $this->h5pC->fs->getTmpPath();
    mkdir($tmpPath, 0777, true);

    try {
      // Create content folder and populate with files
      $this->h5pC->fs->exportContent($content['id'], "{$tmpPath}/content");
    }
    catch (Exception $e) {
      $this->h5pF->setErrorMessage($this->h5pF->t($e->getMessage()), 'failed-creating-export-file');
      H5PCore::deleteFileTree($tmpPath);
      return FALSE;
    }

    // Update content.json with content from database
    file_put_contents("{$tmpPath}/content/content.json", $content['params']);

    // Make embedType into an array
    $embedTypes = explode(', ', $content['embedType']);

    // Build h5p.json, the en-/de-coding will ensure proper escaping
    $h5pJson = array (
      'title' => $content['title'],
      'language' => (isset($content['language']) && strlen(trim($content['language'])) !== 0) ? $content['language'] : 'und',
      'mainLibrary' => $content['library']['name'],
      'embedTypes' => $embedTypes
    );

    foreach(array('authors', 'source', 'license', 'licenseVersion', 'licenseExtras' ,'yearFrom', 'yearTo', 'changes', 'authorComments') as $field) {
      if (isset($content['metadata'][$field])) {
        if (($field !== 'authors' && $field !== 'changes') || (count($content['metadata'][$field]) > 0)) {
          $h5pJson[$field] = json_decode(json_encode($content['metadata'][$field], TRUE));
        }
      }
    }

    // Remove all values that are not set
    foreach ($h5pJson as $key => $value) {
      if (!isset($value)) {
        unset($h5pJson[$key]);
      }
    }

    // Add dependencies to h5p
    foreach ($content['dependencies'] as $dependency) {
      $library = $dependency['library'];

      try {
        $exportFolder = NULL;

        // Determine path of export library
        if (isset($this->h5pC) && isset($this->h5pC->h5pD)) {

          // Tries to find library in development folder
          $isDevLibrary = $this->h5pC->h5pD->getLibrary(
              $library['machineName'],
              $library['majorVersion'],
              $library['minorVersion']
          );

          if ($isDevLibrary !== NULL) {
            $exportFolder = "/" . $library['path'];
          }
        }

        // Export required libraries
        $this->h5pC->fs->exportLibrary($library, $tmpPath, $exportFolder);
      }
      catch (Exception $e) {
        $this->h5pF->setErrorMessage($this->h5pF->t($e->getMessage()), 'failed-creating-export-file');
        H5PCore::deleteFileTree($tmpPath);
        return FALSE;
      }

      // Do not add editor dependencies to h5p json.
      if ($dependency['type'] === 'editor') {
        continue;
      }

      // Add to h5p.json dependencies
      $h5pJson[$dependency['type'] . 'Dependencies'][] = array(
        'machineName' => $library['machineName'],
        'majorVersion' => $library['majorVersion'],
        'minorVersion' => $library['minorVersion']
      );
    }

    // Save h5p.json
    $results = print_r(json_encode($h5pJson), true);
    file_put_contents("{$tmpPath}/h5p.json", $results);

    // Get a complete file list from our tmp dir
    $files = array();
    self::populateFileList($tmpPath, $files);

    // Get path to temporary export target file
    $tmpFile = $this->h5pC->fs->getTmpPath();

    // Create new zip instance.
    $zip = new ZipArchive();
    $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // Add all the files from the tmp dir.
    foreach ($files as $file) {
      // Please note that the zip format has no concept of folders, we must
      // use forward slashes to separate our directories.
      if (file_exists(realpath($file->absolutePath))) {
        $zip->addFile(realpath($file->absolutePath), $file->relativePath);
      }
    }

    // Close zip and remove tmp dir
    $zip->close();
    H5PCore::deleteFileTree($tmpPath);

    $filename = $content['slug'] . '-' . $content['id'] . '.h5p';
    try {
      // Save export
      $this->h5pC->fs->saveExport($tmpFile, $filename);
    }
    catch (Exception $e) {
      $this->h5pF->setErrorMessage($this->h5pF->t($e->getMessage()), 'failed-creating-export-file');
      return false;
    }

    unlink($tmpFile);
    $this->h5pF->afterExportCreated($content, $filename);

    return true;
  }

  /**
   * Recursive function the will add the files of the given directory to the
   * given files list. All files are objects with an absolute path and
   * a relative path. The relative path is forward slashes only! Great for
   * use in zip files and URLs.
   *
   * @param string $dir path
   * @param array $files list
   * @param string $relative prefix. Optional
   */
  private static function populateFileList($dir, &$files, $relative = '') {
    $strip = strlen($dir) + 1;
    $contents = glob($dir . DIRECTORY_SEPARATOR . '*');
    if (!empty($contents)) {
      foreach ($contents as $file) {
        $rel = $relative . substr($file, $strip);
        if (is_dir($file)) {
          self::populateFileList($file, $files, $rel . '/');
        }
        else {
          $files[] = (object) array(
            'absolutePath' => $file,
            'relativePath' => $rel
          );
        }
      }
    }
  }

  /**
   * Delete .h5p file
   *
   * @param array $content object
   */
  public function deleteExport($content) {
    $this->h5pC->fs->deleteExport(($content['slug'] ? $content['slug'] . '-' : '') . $content['id'] . '.h5p');
  }

  /**
   * Add editor libraries to the list of libraries
   *
   * These are not supposed to go into h5p.json, but must be included with the rest
   * of the libraries
   *
   * TODO This is a private function that is not currently being used
   *
   * @param array $libraries
   *  List of libraries keyed by machineName
   * @param array $editorLibraries
   *  List of libraries keyed by machineName
   * @return array List of libraries keyed by machineName
   */
  private function addEditorLibraries($libraries, $editorLibraries) {
    foreach ($editorLibraries as $editorLibrary) {
      $libraries[$editorLibrary['machineName']] = $editorLibrary;
    }
    return $libraries;
  }
}