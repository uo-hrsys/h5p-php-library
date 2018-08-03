<?php
/**
 * This class is used for saving H5P files
 */
class H5PStorage {

  public $h5pF;
  public $h5pC;

  public $contentId = NULL; // Quick fix so WP can get ID of new content.

  /**
   * Constructor for the H5PStorage
   *
   * @param H5PFrameworkInterface|object $H5PFramework
   *  The frameworks implementation of the H5PFrameworkInterface
   * @param H5PCore $H5PCore
   */
  public function __construct(H5PFrameworkInterface $H5PFramework, H5PCore $H5PCore) {
    $this->h5pF = $H5PFramework;
    $this->h5pC = $H5PCore;
  }

  /**
   * Saves a H5P file
   *
   * @param null $content
   * @param int $contentMainId
   *  The main id for the content we are saving. This is used if the framework
   *  we're integrating with uses content id's and version id's
   * @param bool $skipContent
   * @param array $options
   * @return bool TRUE if one or more libraries were updated
   * TRUE if one or more libraries were updated
   * FALSE otherwise
   */
  public function savePackage($content = NULL, $contentMainId = NULL, $skipContent = FALSE, $options = array()) {
    if ($this->h5pC->mayUpdateLibraries()) {
      // Save the libraries we processed during validation
      $this->saveLibraries();
    }

    if (!$skipContent) {
      $basePath = $this->h5pF->getUploadedH5pFolderPath();
      $current_path = $basePath . DIRECTORY_SEPARATOR . 'content';

      // Save content
      if ($content === NULL) {
        $content = array();
      }
      if (!is_array($content)) {
        $content = array('id' => $content);
      }

      // Find main library version
      foreach ($this->h5pC->mainJsonData['preloadedDependencies'] as $dep) {
        if ($dep['machineName'] === $this->h5pC->mainJsonData['mainLibrary']) {
          $dep['libraryId'] = $this->h5pC->getLibraryId($dep);
          $content['library'] = $dep;
          break;
        }
      }

      $content['params'] = file_get_contents($current_path . DIRECTORY_SEPARATOR . 'content.json');

      if (isset($options['disable'])) {
        $content['disable'] = $options['disable'];
      }
      $content['id'] = $this->h5pC->saveContent($content, $contentMainId);
      $this->contentId = $content['id'];

      try {
        // Save content folder contents
        $this->h5pC->fs->saveContent($current_path, $content);
      }
      catch (Exception $e) {
        $this->h5pF->setErrorMessage($e->getMessage(), 'save-content-failed');
      }

      // Remove temp content folder
      H5PCore::deleteFileTree($basePath);
    }
  }

  /**
   * Helps savePackage.
   *
   * @return int Number of libraries saved
   */
  private function saveLibraries() {
    // Keep track of the number of libraries that have been saved
    $newOnes = 0;
    $oldOnes = 0;

    // Go through libraries that came with this package
    foreach ($this->h5pC->librariesJsonData as $libString => &$library) {
      // Find local library identifier
      $libraryId = $this->h5pC->getLibraryId($library, $libString);

      // Assume new library
      $new = TRUE;
      if ($libraryId) {
        // Found old library
        $library['libraryId'] = $libraryId;

        if ($this->h5pF->isPatchedLibrary($library)) {
          // This is a newer version than ours. Upgrade!
          $new = FALSE;
        }
        else {
          $library['saveDependencies'] = FALSE;
          // This is an older version, no need to save.
          continue;
        }
      }

      // Indicate that the dependencies of this library should be saved.
      $library['saveDependencies'] = TRUE;

      // Save library meta data
      $this->h5pF->saveLibraryData($library, $new);

      // Save library folder
      $this->h5pC->fs->saveLibrary($library);

      // Remove cached assets that uses this library
      if ($this->h5pC->aggregateAssets && isset($library['libraryId'])) {
        $removedKeys = $this->h5pF->deleteCachedAssets($library['libraryId']);
        $this->h5pC->fs->deleteCachedAssets($removedKeys);
      }

      // Remove tmp folder
      H5PCore::deleteFileTree($library['uploadDirectory']);

      if ($new) {
        $newOnes++;
      }
      else {
        $oldOnes++;
      }
    }

    // Go through the libraries again to save dependencies.
    foreach ($this->h5pC->librariesJsonData as &$library) {
      if (!$library['saveDependencies']) {
        continue;
      }

      // TODO: Should the table be locked for this operation?

      // Remove any old dependencies
      $this->h5pF->deleteLibraryDependencies($library['libraryId']);

      // Insert the different new ones
      if (isset($library['preloadedDependencies'])) {
        $this->h5pF->saveLibraryDependencies($library['libraryId'], $library['preloadedDependencies'], 'preloaded');
      }
      if (isset($library['dynamicDependencies'])) {
        $this->h5pF->saveLibraryDependencies($library['libraryId'], $library['dynamicDependencies'], 'dynamic');
      }
      if (isset($library['editorDependencies'])) {
        $this->h5pF->saveLibraryDependencies($library['libraryId'], $library['editorDependencies'], 'editor');
      }

      // Make sure libraries dependencies, parameter filtering and export files gets regenerated for all content who uses this library.
      $this->h5pF->clearFilteredParameters($library['libraryId']);
    }

    // Tell the user what we've done.
    if ($newOnes && $oldOnes) {
      if ($newOnes === 1)  {
        if ($oldOnes === 1)  {
          // Singular Singular
          $message = $this->h5pF->t('Added %new new H5P library and updated %old old one.', array('%new' => $newOnes, '%old' => $oldOnes));
        }
        else {
          // Singular Plural
          $message = $this->h5pF->t('Added %new new H5P library and updated %old old ones.', array('%new' => $newOnes, '%old' => $oldOnes));
        }
      }
      else {
        // Plural
        if ($oldOnes === 1)  {
          // Plural Singular
          $message = $this->h5pF->t('Added %new new H5P libraries and updated %old old one.', array('%new' => $newOnes, '%old' => $oldOnes));
        }
        else {
          // Plural Plural
          $message = $this->h5pF->t('Added %new new H5P libraries and updated %old old ones.', array('%new' => $newOnes, '%old' => $oldOnes));
        }
      }
    }
    elseif ($newOnes) {
      if ($newOnes === 1)  {
        // Singular
        $message = $this->h5pF->t('Added %new new H5P library.', array('%new' => $newOnes));
      }
      else {
        // Plural
        $message = $this->h5pF->t('Added %new new H5P libraries.', array('%new' => $newOnes));
      }
    }
    elseif ($oldOnes) {
      if ($oldOnes === 1)  {
        // Singular
        $message = $this->h5pF->t('Updated %old H5P library.', array('%old' => $oldOnes));
      }
      else {
        // Plural
        $message = $this->h5pF->t('Updated %old H5P libraries.', array('%old' => $oldOnes));
      }
    }

    if (isset($message)) {
      $this->h5pF->setInfoMessage($message);
    }
  }

  /**
   * Delete an H5P package
   *
   * @param $content
   */
  public function deletePackage($content) {
    $this->h5pC->fs->deleteContent($content);
    $this->h5pC->fs->deleteExport(($content['slug'] ? $content['slug'] . '-' : '') . $content['id'] . '.h5p');
    $this->h5pF->deleteContentData($content['id']);
  }

  /**
   * Copy/clone an H5P package
   *
   * May for instance be used if the content is being revisioned without
   * uploading a new H5P package
   *
   * @param int $contentId
   *  The new content id
   * @param int $copyFromId
   *  The content id of the content that should be cloned
   * @param int $contentMainId
   *  The main id of the new content (used in frameworks that support revisioning)
   */
  public function copyPackage($contentId, $copyFromId, $contentMainId = NULL) {
    $this->h5pC->fs->cloneContent($copyFromId, $contentId);
    $this->h5pF->copyLibraryUsage($contentId, $copyFromId, $contentMainId);
  }
}