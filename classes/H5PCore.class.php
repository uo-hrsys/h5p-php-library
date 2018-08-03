<?php
/**
 * Functions and storage shared by the other H5P classes
 */
class H5PCore {

  public static $coreApi = array(
    'majorVersion' => 1,
    'minorVersion' => 16
  );
  public static $styles = array(
    'styles/h5p.css',
    'styles/h5p-confirmation-dialog.css',
    'styles/h5p-core-button.css'
  );
  public static $scripts = array(
    'js/jquery.js',
    'js/h5p.js',
    'js/h5p-event-dispatcher.js',
    'js/h5p-x-api-event.js',
    'js/h5p-x-api.js',
    'js/h5p-content-type.js',
    'js/h5p-confirmation-dialog.js',
    'js/h5p-action-bar.js'
  );
  public static $adminScripts = array(
    'js/jquery.js',
    'js/h5p-utils.js',
  );

  public static $defaultContentWhitelist = 'json png jpg jpeg gif bmp tif tiff svg eot ttf woff woff2 otf webm mp4 ogg mp3 wav txt pdf rtf doc docx xls xlsx ppt pptx odt ods odp xml csv diff patch swf md textile vtt webvtt';
  public static $defaultLibraryWhitelistExtras = 'js css';

  public $librariesJsonData, $contentJsonData, $mainJsonData, $h5pF, $fs, $h5pD, $disableFileCheck;
  const SECONDS_IN_WEEK = 604800;

  private $exportEnabled;

  // Disable flags
  const DISABLE_NONE = 0;
  const DISABLE_FRAME = 1;
  const DISABLE_DOWNLOAD = 2;
  const DISABLE_EMBED = 4;
  const DISABLE_COPYRIGHT = 8;
  const DISABLE_ABOUT = 16;

  const DISPLAY_OPTION_FRAME = 'frame';
  const DISPLAY_OPTION_DOWNLOAD = 'export';
  const DISPLAY_OPTION_EMBED = 'embed';
  const DISPLAY_OPTION_COPYRIGHT = 'copyright';
  const DISPLAY_OPTION_ABOUT = 'icon';

  // Map flags to string
  public static $disable = array(
    self::DISABLE_FRAME => self::DISPLAY_OPTION_FRAME,
    self::DISABLE_DOWNLOAD => self::DISPLAY_OPTION_DOWNLOAD,
    self::DISABLE_EMBED => self::DISPLAY_OPTION_EMBED,
    self::DISABLE_COPYRIGHT => self::DISPLAY_OPTION_COPYRIGHT
  );

  /**
   * Constructor for the H5PCore
   *
   * @param H5PFrameworkInterface $H5PFramework
   *  The frameworks implementation of the H5PFrameworkInterface
   * @param string|\H5PFileStorage $path H5P file storage directory or class.
   * @param string $url To file storage directory.
   * @param string $language code. Defaults to english.
   * @param boolean $export enabled?
   */
  public function __construct(H5PFrameworkInterface $H5PFramework, $path, $url, $language = 'en', $export = FALSE) {
    $this->h5pF = $H5PFramework;

    $this->fs = ($path instanceof \H5PFileStorage ? $path : new \H5PDefaultStorage($path));

    $this->url = $url;
    $this->exportEnabled = $export;
    $this->development_mode = H5PDevelopment::MODE_NONE;

    $this->aggregateAssets = FALSE; // Off by default.. for now

    $this->detectSiteType();
    $this->fullPluginPath = preg_replace('/\/[^\/]+[\/]?$/', '' , dirname(__FILE__));

    // Standard regex for converting copied files paths
    $this->relativePathRegExp = '/^((\.\.\/){1,2})(.*content\/)?(\d+|editor)\/(.+)$/';
  }



  /**
   * Save content and clear cache.
   *
   * @param array $content
   * @param null|int $contentMainId
   * @return int Content ID
   */
  public function saveContent($content, $contentMainId = NULL) {
    if (isset($content['id'])) {
      $this->h5pF->updateContent($content, $contentMainId);
    }
    else {
      $content['id'] = $this->h5pF->insertContent($content, $contentMainId);
    }

    // Some user data for content has to be reset when the content changes.
    $this->h5pF->resetContentUserData($contentMainId ? $contentMainId : $content['id']);

    return $content['id'];
  }

  /**
   * Load content.
   *
   * @param int $id for content.
   * @return object
   */
  public function loadContent($id) {
    $content = $this->h5pF->loadContent($id);

    if ($content !== NULL) {
      $content['library'] = array(
        'id' => $content['libraryId'],
        'name' => $content['libraryName'],
        'majorVersion' => $content['libraryMajorVersion'],
        'minorVersion' => $content['libraryMinorVersion'],
        'embedTypes' => $content['libraryEmbedTypes'],
        'fullscreen' => $content['libraryFullscreen'],
      );
      unset($content['libraryId'], $content['libraryName'], $content['libraryEmbedTypes'], $content['libraryFullscreen']);

//      // TODO: Move to filterParameters?
//      if (isset($this->h5pD)) {
//        // TODO: Remove Drupal specific stuff
//        $json_content_path = file_create_path(file_directory_path() . '/' . variable_get('h5p_default_path', 'h5p') . '/content/' . $id . '/content.json');
//        if (file_exists($json_content_path) === TRUE) {
//          $json_content = file_get_contents($json_content_path);
//          if (json_decode($json_content, TRUE) !== FALSE) {
//            drupal_set_message(t('Invalid json in json content'), 'warning');
//          }
//          $content['params'] = $json_content;
//        }
//      }
    }

    return $content;
  }

  /**
   * Filter content run parameters, rebuild content dependency cache and export file.
   *
   * @param Object|array $content
   * @return Object NULL on failure.
   */
  public function filterParameters(&$content) {
    if (!empty($content['filtered']) &&
        (!$this->exportEnabled ||
         ($content['slug'] &&
          $this->fs->hasExport($content['slug'] . '-' . $content['id'] . '.h5p')))) {
      return $content['filtered'];
    }

    // Validate and filter against main library semantics.
    $validator = new H5PContentValidator($this->h5pF, $this);
    $params = (object) array(
      'library' => H5PCore::libraryToString($content['library']),
      'params' => json_decode($content['params'])
    );
    if (!$params->params) {
      return NULL;
    }
    $validator->validateLibrary($params, (object) array('options' => array($params->library)));

    $params = json_encode($params->params);

    // Update content dependencies.
    $content['dependencies'] = $validator->getDependencies();

    // Sometimes the parameters are filtered before content has been created
    if ($content['id']) {
      $this->h5pF->deleteLibraryUsage($content['id']);
      $this->h5pF->saveLibraryUsage($content['id'], $content['dependencies']);

      if (!$content['slug']) {
        $content['slug'] = $this->generateContentSlug($content);

        // Remove old export file
        $this->fs->deleteExport($content['id'] . '.h5p');
      }

      if ($this->exportEnabled) {
        // Recreate export file
        $exporter = new H5PExport($this->h5pF, $this);
        $exporter->createExportFile($content);
      }

      // Cache.
      $this->h5pF->updateContentFields($content['id'], array(
        'filtered' => $params,
        'slug' => $content['slug']
      ));
    }
    return $params;
  }

  /**
   * Generate content slug
   *
   * @param array $content object
   * @return string unique content slug
   */
  private function generateContentSlug($content) {
    $slug = H5PCore::slugify($content['title']);

    $available = NULL;
    while (!$available) {
      if ($available === FALSE) {
        // If not available, add number suffix.
        $matches = array();
        if (preg_match('/(.+-)([0-9]+)$/', $slug, $matches)) {
          $slug = $matches[1] . (intval($matches[2]) + 1);
        }
        else {
          $slug .=  '-2';
        }
      }
      $available = $this->h5pF->isContentSlugAvailable($slug);
    }

    return $slug;
  }

  /**
   * Find the files required for this content to work.
   *
   * @param int $id for content.
   * @param null $type
   * @return array
   */
  public function loadContentDependencies($id, $type = NULL) {
    $dependencies = $this->h5pF->loadContentDependencies($id, $type);

    if (isset($this->h5pD)) {
      $developmentLibraries = $this->h5pD->getLibraries();

      foreach ($dependencies as $key => $dependency) {
        $libraryString = H5PCore::libraryToString($dependency);
        if (isset($developmentLibraries[$libraryString])) {
          $developmentLibraries[$libraryString]['dependencyType'] = $dependencies[$key]['dependencyType'];
          $dependencies[$key] = $developmentLibraries[$libraryString];
        }
      }
    }

    return $dependencies;
  }

  /**
   * Get all dependency assets of the given type
   *
   * @param array $dependency
   * @param string $type
   * @param array $assets
   * @param string $prefix Optional. Make paths relative to another dir.
   */
  private function getDependencyAssets($dependency, $type, &$assets, $prefix = '') {
    // Check if dependency has any files of this type
    if (empty($dependency[$type]) || $dependency[$type][0] === '') {
      return;
    }

    // Check if we should skip CSS.
    if ($type === 'preloadedCss' && (isset($dependency['dropCss']) && $dependency['dropCss'] === '1')) {
      return;
    }
    foreach ($dependency[$type] as $file) {
      $assets[] = (object) array(
        'path' => $prefix . '/' . $dependency['path'] . '/' . trim(is_array($file) ? $file['path'] : $file),
        'version' => $dependency['version']
      );
    }
  }

  /**
   * Combines path with cache buster / version.
   *
   * @param array $assets
   * @return array
   */
  public function getAssetsUrls($assets) {
    $urls = array();

    foreach ($assets as $asset) {
      $url = $asset->path;

      // Add URL prefix if not external
      if (strpos($asset->path, '://') === FALSE) {
        $url = $this->url . $url;
      }

      // Add version/cache buster if set
      if (isset($asset->version)) {
        $url .= $asset->version;
      }

      $urls[] = $url;
    }

    return $urls;
  }

  /**
   * Return file paths for all dependencies files.
   *
   * @param array $dependencies
   * @param string $prefix Optional. Make paths relative to another dir.
   * @return array files.
   */
  public function getDependenciesFiles($dependencies, $prefix = '') {
    // Build files list for assets
    $files = array(
      'scripts' => array(),
      'styles' => array()
    );

    $key = null;

    // Avoid caching empty files
    if (empty($dependencies)) {
      return $files;
    }

    if ($this->aggregateAssets) {
      // Get aggregated files for assets
      $key = self::getDependenciesHash($dependencies);

      $cachedAssets = $this->fs->getCachedAssets($key);
      if ($cachedAssets !== NULL) {
        return array_merge($files, $cachedAssets); // Using cached assets
      }
    }

    // Using content dependencies
    foreach ($dependencies as $dependency) {
      if (isset($dependency['path']) === FALSE) {
        $dependency['path'] = 'libraries/' . H5PCore::libraryToString($dependency, TRUE);
        $dependency['preloadedJs'] = explode(',', $dependency['preloadedJs']);
        $dependency['preloadedCss'] = explode(',', $dependency['preloadedCss']);
      }
      $dependency['version'] = "?ver={$dependency['majorVersion']}.{$dependency['minorVersion']}.{$dependency['patchVersion']}";
      $this->getDependencyAssets($dependency, 'preloadedJs', $files['scripts'], $prefix);
      $this->getDependencyAssets($dependency, 'preloadedCss', $files['styles'], $prefix);
    }

    if ($this->aggregateAssets) {
      // Aggregate and store assets
      $this->fs->cacheAssets($files, $key);

      // Keep track of which libraries have been cached in case they are updated
      $this->h5pF->saveCachedAssets($key, $dependencies);
    }

    return $files;
  }

  private static function getDependenciesHash(&$dependencies) {
    // Build hash of dependencies
    $toHash = array();

    // Use unique identifier for each library version
    foreach ($dependencies as $dep) {
      $toHash[] = "{$dep['machineName']}-{$dep['majorVersion']}.{$dep['minorVersion']}.{$dep['patchVersion']}";
    }

    // Sort in case the same dependencies comes in a different order
    sort($toHash);

    // Calculate hash sum
    return hash('sha1', implode('', $toHash));
  }

  /**
   * Load library semantics.
   *
   * @param $name
   * @param $majorVersion
   * @param $minorVersion
   * @return string
   */
  public function loadLibrarySemantics($name, $majorVersion, $minorVersion) {
    $semantics = NULL;
    if (isset($this->h5pD)) {
      // Try to load from dev lib
      $semantics = $this->h5pD->getSemantics($name, $majorVersion, $minorVersion);
    }

    if ($semantics === NULL) {
      // Try to load from DB.
      $semantics = $this->h5pF->loadLibrarySemantics($name, $majorVersion, $minorVersion);
    }

    if ($semantics !== NULL) {
      $semantics = json_decode($semantics);
      $this->h5pF->alterLibrarySemantics($semantics, $name, $majorVersion, $minorVersion);
    }

    return $semantics;
  }

  /**
   * Load library.
   *
   * @param $name
   * @param $majorVersion
   * @param $minorVersion
   * @return array or null.
   */
  public function loadLibrary($name, $majorVersion, $minorVersion) {
    $library = NULL;
    if (isset($this->h5pD)) {
      // Try to load from dev
      $library = $this->h5pD->getLibrary($name, $majorVersion, $minorVersion);
      if ($library !== NULL) {
        $library['semantics'] = $this->h5pD->getSemantics($name, $majorVersion, $minorVersion);
      }
    }

    if ($library === NULL) {
      // Try to load from DB.
      $library = $this->h5pF->loadLibrary($name, $majorVersion, $minorVersion);
    }

    return $library;
  }

  /**
   * Deletes a library
   *
   * @param stdClass $libraryId
   */
  public function deleteLibrary($libraryId) {
    $this->h5pF->deleteLibrary($libraryId);
  }

  /**
   * Recursive. Goes through the dependency tree for the given library and
   * adds all the dependencies to the given array in a flat format.
   *
   * @param $dependencies
   * @param array $library To find all dependencies for.
   * @param int $nextWeight An integer determining the order of the libraries
   *  when they are loaded
   * @param bool $editor Used internally to force all preloaded sub dependencies
   *  of an editor dependency to be editor dependencies.
   * @return int
   */
  public function findLibraryDependencies(&$dependencies, $library, $nextWeight = 1, $editor = FALSE) {
    foreach (array('dynamic', 'preloaded', 'editor') as $type) {
      $property = $type . 'Dependencies';
      if (!isset($library[$property])) {
        continue; // Skip, no such dependencies.
      }

      if ($type === 'preloaded' && $editor === TRUE) {
        // All preloaded dependencies of an editor library is set to editor.
        $type = 'editor';
      }

      foreach ($library[$property] as $dependency) {
        $dependencyKey = $type . '-' . $dependency['machineName'];
        if (isset($dependencies[$dependencyKey]) === TRUE) {
          continue; // Skip, already have this.
        }

        $dependencyLibrary = $this->loadLibrary($dependency['machineName'], $dependency['majorVersion'], $dependency['minorVersion']);
        if ($dependencyLibrary) {
          $dependencies[$dependencyKey] = array(
            'library' => $dependencyLibrary,
            'type' => $type
          );
          $nextWeight = $this->findLibraryDependencies($dependencies, $dependencyLibrary, $nextWeight, $type === 'editor');
          $dependencies[$dependencyKey]['weight'] = $nextWeight++;
        }
        else {
          // This site is missing a dependency!
          $this->h5pF->setErrorMessage($this->h5pF->t('Missing dependency @dep required by @lib.', array('@dep' => H5PCore::libraryToString($dependency), '@lib' => H5PCore::libraryToString($library))), 'missing-library-dependency');
        }
      }
    }
    return $nextWeight;
  }

  /**
   * Check if a library is of the version we're looking for
   *
   * Same version means that the majorVersion and minorVersion is the same
   *
   * @param array $library
   *  Data from library.json
   * @param array $dependency
   *  Definition of what library we're looking for
   * @return boolean
   *  TRUE if the library is the same version as the dependency
   *  FALSE otherwise
   */
  public function isSameVersion($library, $dependency) {
    if ($library['machineName'] != $dependency['machineName']) {
      return FALSE;
    }
    if ($library['majorVersion'] != $dependency['majorVersion']) {
      return FALSE;
    }
    if ($library['minorVersion'] != $dependency['minorVersion']) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Recursive function for removing directories.
   *
   * @param string $dir
   *  Path to the directory we'll be deleting
   * @return boolean
   *  Indicates if the directory existed.
   */
  public static function deleteFileTree($dir) {
    if (!is_dir($dir)) {
      return false;
    }
    if (is_link($dir)) {
      // Do not traverse and delete linked content, simply unlink.
      unlink($dir);
      return;
    }
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
      $filepath = "$dir/$file";
      // Note that links may resolve as directories
      if (!is_dir($filepath) || is_link($filepath)) {
        // Unlink files and links
        unlink($filepath);
      }
      else {
        // Traverse subdir and delete files
        self::deleteFileTree($filepath);
      }
    }
    return rmdir($dir);
  }

  /**
   * Writes library data as string on the form {machineName} {majorVersion}.{minorVersion}
   *
   * @param array $library
   *  With keys machineName, majorVersion and minorVersion
   * @param boolean $folderName
   *  Use hyphen instead of space in returned string.
   * @return string
   *  On the form {machineName} {majorVersion}.{minorVersion}
   */
  public static function libraryToString($library, $folderName = FALSE) {
    return (isset($library['machineName']) ? $library['machineName'] : $library['name']) . ($folderName ? '-' : ' ') . $library['majorVersion'] . '.' . $library['minorVersion'];
  }

  /**
   * Parses library data from a string on the form {machineName} {majorVersion}.{minorVersion}
   *
   * @param string $libraryString
   *  On the form {machineName} {majorVersion}.{minorVersion}
   * @return array|FALSE
   *  With keys machineName, majorVersion and minorVersion.
   *  Returns FALSE only if string is not parsable in the normal library
   *  string formats "Lib.Name-x.y" or "Lib.Name x.y"
   */
  public static function libraryFromString($libraryString) {
    $re = '/^([\w0-9\-\.]{1,255})[\-\ ]([0-9]{1,5})\.([0-9]{1,5})$/i';
    $matches = array();
    $res = preg_match($re, $libraryString, $matches);
    if ($res) {
      return array(
        'machineName' => $matches[1],
        'majorVersion' => $matches[2],
        'minorVersion' => $matches[3]
      );
    }
    return FALSE;
  }

  /**
   * Determine the correct embed type to use.
   *
   * @param $contentEmbedType
   * @param $libraryEmbedTypes
   * @return string 'div' or 'iframe'.
   */
  public static function determineEmbedType($contentEmbedType, $libraryEmbedTypes) {
    // Detect content embed type
    $embedType = strpos(strtolower($contentEmbedType), 'div') !== FALSE ? 'div' : 'iframe';

    if ($libraryEmbedTypes !== NULL && $libraryEmbedTypes !== '') {
      // Check that embed type is available for library
      $embedTypes = strtolower($libraryEmbedTypes);
      if (strpos($embedTypes, $embedType) === FALSE) {
        // Not available, pick default.
        $embedType = strpos($embedTypes, 'div') !== FALSE ? 'div' : 'iframe';
      }
    }

    return $embedType;
  }

  /**
   * Get the absolute version for the library as a human readable string.
   *
   * @param object $library
   * @return string
   */
  public static function libraryVersion($library) {
    return $library->major_version . '.' . $library->minor_version . '.' . $library->patch_version;
  }

  /**
   * Determine which versions content with the given library can be upgraded to.
   *
   * @param object $library
   * @param array $versions
   * @return array
   */
  public function getUpgrades($library, $versions) {
   $upgrades = array();

   foreach ($versions as $upgrade) {
     if ($upgrade->major_version > $library->major_version || $upgrade->major_version === $library->major_version && $upgrade->minor_version > $library->minor_version) {
       $upgrades[$upgrade->id] = H5PCore::libraryVersion($upgrade);
     }
   }

   return $upgrades;
  }

  /**
   * Converts all the properties of the given object or array from
   * snake_case to camelCase. Useful after fetching data from the database.
   *
   * Note that some databases does not support camelCase.
   *
   * @param mixed $arr input
   * @param boolean $obj return object
   * @return mixed object or array
   */
  public static function snakeToCamel($arr, $obj = false) {
    $newArr = array();

    foreach ($arr as $key => $val) {
      $next = -1;
      while (($next = strpos($key, '_', $next + 1)) !== FALSE) {
        $key = substr_replace($key, strtoupper($key{$next + 1}), $next, 2);
      }

      $newArr[$key] = $val;
    }

    return $obj ? (object) $newArr : $newArr;
  }

  /**
   * Detects if the site was accessed from localhost,
   * through a local network or from the internet.
   */
  public function detectSiteType() {
    $type = $this->h5pF->getOption('site_type', 'local');

    // Determine remote/visitor origin
    if ($type === 'network' ||
        ($type === 'local' &&
         isset($_SERVER['REMOTE_ADDR']) &&
         !preg_match('/^localhost$|^127(?:\.[0-9]+){0,2}\.[0-9]+$|^(?:0*\:)*?:?0*1$/i', $_SERVER['REMOTE_ADDR']))) {
      if (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
        // Internet
        $this->h5pF->setOption('site_type', 'internet');
      }
      elseif ($type === 'local') {
        // Local network
        $this->h5pF->setOption('site_type', 'network');
      }
    }
  }

  /**
   * Get a list of installed libraries, different minor versions will
   * return separate entries.
   *
   * @return array
   *  A distinct array of installed libraries
   */
  public function getLibrariesInstalled() {
    $librariesInstalled = array();
    $libs = $this->h5pF->loadLibraries();

    foreach($libs as $libName => $library) {
      foreach($library as $libVersion) {
        $librariesInstalled[$libName.' '.$libVersion->major_version.'.'.$libVersion->minor_version] = $libVersion->patch_version;
      }
    }

    return $librariesInstalled;
  }

  /**
   * Easy way to combine similar data sets.
   *
   * @param array $inputs Multiple arrays with data
   * @return array
   */
  public function combineArrayValues($inputs) {
    $results = array();
    foreach ($inputs as $index => $values) {
      foreach ($values as $key => $value) {
        $results[$key][$index] = $value;
      }
    }
    return $results;
  }

  /**
   * Communicate with H5P.org and get content type cache. Each platform
   * implementation is responsible for invoking this, eg using cron
   *
   * @param bool $fetchingDisabled
   *
   * @return bool|object Returns endpoint data if found, otherwise FALSE
   */
  public function fetchLibrariesMetadata($fetchingDisabled = FALSE) {
    // Gather data
    $uuid = $this->h5pF->getOption('site_uuid', '');
    $platform = $this->h5pF->getPlatformInfo();
    $registrationData = array(
      'uuid' => $uuid,
      'platform_name' => $platform['name'],
      'platform_version' => $platform['version'],
      'h5p_version' => $platform['h5pVersion'],
      'disabled' => $fetchingDisabled ? 1 : 0,
      'local_id' => hash('crc32', $this->fullPluginPath),
      'type' => $this->h5pF->getOption('site_type', 'local'),
      'core_api_version' => H5PCore::$coreApi['majorVersion'] . '.' .
                            H5PCore::$coreApi['minorVersion']
    );

    // Register site if it is not registered
    if (empty($uuid)) {
      $registration = $this->h5pF->fetchExternalData(H5PHubEndpoints::createURL(H5PHubEndpoints::SITES), $registrationData);

      // Failed retrieving uuid
      if (!$registration) {
        $errorMessage = $this->h5pF->t('Site could not be registered with the hub. Please contact your site administrator.');
        $this->h5pF->setErrorMessage($errorMessage);
        $this->h5pF->setErrorMessage(
          $this->h5pF->t('The H5P Hub has been disabled until this problem can be resolved. You may still upload libraries through the "H5P Libraries" page.'),
          'registration-failed-hub-disabled'
        );
        return FALSE;
      }

      // Successfully retrieved new uuid
      $json = json_decode($registration);
      $registrationData['uuid'] = $json->uuid;
      $this->h5pF->setOption('site_uuid', $json->uuid);
      $this->h5pF->setInfoMessage(
        $this->h5pF->t('Your site was successfully registered with the H5P Hub.')
      );
      // TODO: Uncomment when key is once again available in H5P Settings
//      $this->h5pF->setInfoMessage(
//        $this->h5pF->t('You have been provided a unique key that identifies you with the Hub when receiving new updates. The key is available for viewing in the "H5P Settings" page.')
//      );
    }

    if ($this->h5pF->getOption('send_usage_statistics', TRUE)) {
      $siteData = array_merge(
        $registrationData,
        array(
          'num_authors' => $this->h5pF->getNumAuthors(),
          'libraries'   => json_encode($this->combineArrayValues(array(
            'patch'            => $this->getLibrariesInstalled(),
            'content'          => $this->h5pF->getLibraryContentCount(),
            'loaded'           => $this->h5pF->getLibraryStats('library'),
            'created'          => $this->h5pF->getLibraryStats('content create'),
            'createdUpload'    => $this->h5pF->getLibraryStats('content create upload'),
            'deleted'          => $this->h5pF->getLibraryStats('content delete'),
            'resultViews'      => $this->h5pF->getLibraryStats('results content'),
            'shortcodeInserts' => $this->h5pF->getLibraryStats('content shortcode insert')
          )))
        )
      );
    }
    else {
      $siteData = $registrationData;
    }

    $result = $this->updateContentTypeCache($siteData);

    // No data received
    if (!$result || empty($result)) {
      return FALSE;
    }

    // Handle libraries metadata
    if (isset($result->libraries)) {
      foreach ($result->libraries as $library) {
        if (isset($library->tutorialUrl) && isset($library->machineName)) {
          $this->h5pF->setLibraryTutorialUrl($library->machineNamee, $library->tutorialUrl);
        }
      }
    }

    return $result;
  }

  /**
   * Create representation of display options as int
   *
   * @param array $sources
   * @param int $current
   * @return int
   */
  public function getStorableDisplayOptions(&$sources, $current) {
    // Download - force setting it if always on or always off
    $download = $this->h5pF->getOption(self::DISPLAY_OPTION_DOWNLOAD, H5PDisplayOptionBehaviour::ALWAYS_SHOW);
    if ($download == H5PDisplayOptionBehaviour::ALWAYS_SHOW ||
        $download == H5PDisplayOptionBehaviour::NEVER_SHOW) {
      $sources[self::DISPLAY_OPTION_DOWNLOAD] = ($download == H5PDisplayOptionBehaviour::ALWAYS_SHOW);
    }

    // Embed - force setting it if always on or always off
    $embed = $this->h5pF->getOption(self::DISPLAY_OPTION_EMBED, H5PDisplayOptionBehaviour::ALWAYS_SHOW);
    if ($embed == H5PDisplayOptionBehaviour::ALWAYS_SHOW ||
        $embed == H5PDisplayOptionBehaviour::NEVER_SHOW) {
      $sources[self::DISPLAY_OPTION_EMBED] = ($embed == H5PDisplayOptionBehaviour::ALWAYS_SHOW);
    }

    foreach (H5PCore::$disable as $bit => $option) {
      if (!isset($sources[$option]) || !$sources[$option]) {
        $current |= $bit; // Disable
      }
      else {
        $current &= ~$bit; // Enable
      }
    }
    return $current;
  }

  /**
   * Determine display options visibility and value on edit
   *
   * @param int $disable
   * @return array
   */
  public function getDisplayOptionsForEdit($disable = NULL) {
    $display_options = array();

    $current_display_options = $disable === NULL ? array() : $this->getDisplayOptionsAsArray($disable);

    if ($this->h5pF->getOption(self::DISPLAY_OPTION_FRAME, TRUE)) {
      $display_options[self::DISPLAY_OPTION_FRAME] =
        isset($current_display_options[self::DISPLAY_OPTION_FRAME]) ?
        $current_display_options[self::DISPLAY_OPTION_FRAME] :
        TRUE;

      // Download
      $export = $this->h5pF->getOption(self::DISPLAY_OPTION_DOWNLOAD, H5PDisplayOptionBehaviour::ALWAYS_SHOW);
      if ($export == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_ON ||
          $export == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_OFF) {
        $display_options[self::DISPLAY_OPTION_DOWNLOAD] =
          isset($current_display_options[self::DISPLAY_OPTION_DOWNLOAD]) ?
          $current_display_options[self::DISPLAY_OPTION_DOWNLOAD] :
          ($export == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_ON);
      }

      // Embed
      $embed = $this->h5pF->getOption(self::DISPLAY_OPTION_EMBED, H5PDisplayOptionBehaviour::ALWAYS_SHOW);
      if ($embed == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_ON ||
          $embed == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_OFF) {
        $display_options[self::DISPLAY_OPTION_EMBED] =
          isset($current_display_options[self::DISPLAY_OPTION_EMBED]) ?
          $current_display_options[self::DISPLAY_OPTION_EMBED] :
          ($embed == H5PDisplayOptionBehaviour::CONTROLLED_BY_AUTHOR_DEFAULT_ON);
      }

      // Copyright
      if ($this->h5pF->getOption(self::DISPLAY_OPTION_COPYRIGHT, TRUE)) {
        $display_options[self::DISPLAY_OPTION_COPYRIGHT] =
          isset($current_display_options[self::DISPLAY_OPTION_COPYRIGHT]) ?
          $current_display_options[self::DISPLAY_OPTION_COPYRIGHT] :
          TRUE;
      }
    }

    return $display_options;
  }

  /**
   * Helper function used to figure out embed & download behaviour
   *
   * @param string $option_name
   * @param H5PPermission $permission
   * @param int $id
   * @param bool &$value
   */
  private function setDisplayOptionOverrides($option_name, $permission, $id, &$value) {
    $behaviour = $this->h5pF->getOption($option_name, H5PDisplayOptionBehaviour::ALWAYS_SHOW);
    // If never show globally, force hide
    if ($behaviour == H5PDisplayOptionBehaviour::NEVER_SHOW) {
      $value = false;
    }
    elseif ($behaviour == H5PDisplayOptionBehaviour::ALWAYS_SHOW) {
      // If always show or permissions say so, force show
      $value = true;
    }
    elseif ($behaviour == H5PDisplayOptionBehaviour::CONTROLLED_BY_PERMISSIONS) {
      $value = $this->h5pF->hasPermission($permission, $id);
    }
  }

  /**
   * Determine display option visibility when viewing H5P
   *
   * @param int $display_options
   * @param int  $id Might be content id or user id.
   * Depends on what the platform needs to be able to determine permissions.
   * @return array
   */
  public function getDisplayOptionsForView($disable, $id) {
    $display_options = $this->getDisplayOptionsAsArray($disable);

    if ($this->h5pF->getOption(self::DISPLAY_OPTION_FRAME, TRUE) == FALSE) {
      $display_options[self::DISPLAY_OPTION_FRAME] = false;
    }
    else {
      $this->setDisplayOptionOverrides(self::DISPLAY_OPTION_DOWNLOAD, H5PPermission::DOWNLOAD_H5P, $id, $display_options[self::DISPLAY_OPTION_DOWNLOAD]);
      $this->setDisplayOptionOverrides(self::DISPLAY_OPTION_EMBED, H5PPermission::EMBED_H5P, $id, $display_options[self::DISPLAY_OPTION_EMBED]);

      if ($this->h5pF->getOption(self::DISPLAY_OPTION_COPYRIGHT, TRUE) == FALSE) {
        $display_options[self::DISPLAY_OPTION_COPYRIGHT] = false;
      }
    }

    return $display_options;
  }

  /**
   * Convert display options as single byte to array
   *
   * @param int $disable
   * @return array
   */
  private function getDisplayOptionsAsArray($disable) {
    return array(
      self::DISPLAY_OPTION_FRAME => !($disable & H5PCore::DISABLE_FRAME),
      self::DISPLAY_OPTION_DOWNLOAD => !($disable & H5PCore::DISABLE_DOWNLOAD),
      self::DISPLAY_OPTION_EMBED => !($disable & H5PCore::DISABLE_EMBED),
      self::DISPLAY_OPTION_COPYRIGHT => !($disable & H5PCore::DISABLE_COPYRIGHT),
      self::DISPLAY_OPTION_ABOUT => !!$this->h5pF->getOption(self::DISPLAY_OPTION_ABOUT, TRUE),
    );
  }

  /**
   * Small helper for getting the library's ID.
   *
   * @param array $library
   * @param string [$libString]
   * @return int Identifier, or FALSE if non-existent
   */
  public function getLibraryId($library, $libString = NULL) {
    if (!$libString) {
      $libString = self::libraryToString($library);
    }

    if (!isset($libraryIdMap[$libString])) {
      $libraryIdMap[$libString] = $this->h5pF->getLibraryId($library['machineName'], $library['majorVersion'], $library['minorVersion']);
    }

    return $libraryIdMap[$libString];
  }

  /**
   * Convert strings of text into simple kebab case slugs.
   * Very useful for readable urls etc.
   *
   * @param string $input
   * @return string
   */
  public static function slugify($input) {
    // Down low
    $input = strtolower($input);

    // Replace common chars
    $input = str_replace(
      array('æ',  'ø',  'ö', 'ó', 'ô', 'Ò',  'Õ', 'Ý', 'ý', 'ÿ', 'ā', 'ă', 'ą', 'œ', 'å', 'ä', 'á', 'à', 'â', 'ã', 'ç', 'ć', 'ĉ', 'ċ', 'č', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ú', 'ñ', 'ü', 'ù', 'û', 'ß',  'ď', 'đ', 'ē', 'ĕ', 'ė', 'ę', 'ě', 'ĝ', 'ğ', 'ġ', 'ģ', 'ĥ', 'ħ', 'ĩ', 'ī', 'ĭ', 'į', 'ı', 'ĳ',  'ĵ', 'ķ', 'ĺ', 'ļ', 'ľ', 'ŀ', 'ł', 'ń', 'ņ', 'ň', 'ŉ', 'ō', 'ŏ', 'ő', 'ŕ', 'ŗ', 'ř', 'ś', 'ŝ', 'ş', 'š', 'ţ', 'ť', 'ŧ', 'ũ', 'ū', 'ŭ', 'ů', 'ű', 'ų', 'ŵ', 'ŷ', 'ź', 'ż', 'ž', 'ſ', 'ƒ', 'ơ', 'ư', 'ǎ', 'ǐ', 'ǒ', 'ǔ', 'ǖ', 'ǘ', 'ǚ', 'ǜ', 'ǻ', 'ǽ',  'ǿ'),
      array('ae', 'oe', 'o', 'o', 'o', 'oe', 'o', 'o', 'y', 'y', 'y', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'a', 'c', 'c', 'c', 'c', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'u', 'n', 'u', 'u', 'u', 'es', 'd', 'd', 'e', 'e', 'e', 'e', 'e', 'g', 'g', 'g', 'g', 'h', 'h', 'i', 'i', 'i', 'i', 'i', 'ij', 'j', 'k', 'l', 'l', 'l', 'l', 'l', 'n', 'n', 'n', 'n', 'o', 'o', 'o', 'r', 'r', 'r', 's', 's', 's', 's', 't', 't', 't', 'u', 'u', 'u', 'u', 'u', 'u', 'w', 'y', 'z', 'z', 'z', 's', 'f', 'o', 'u', 'a', 'i', 'o', 'u', 'u', 'u', 'u', 'u', 'a', 'ae', 'oe'),
      $input);

    // Replace everything else
    $input = preg_replace('/[^a-z0-9]/', '-', $input);

    // Prevent double hyphen
    $input = preg_replace('/-{2,}/', '-', $input);

    // Prevent hyphen in beginning or end
    $input = trim($input, '-');

    // Prevent to long slug
    if (strlen($input) > 91) {
      $input = substr($input, 0, 92);
    }

    // Prevent empty slug
    if ($input === '') {
      $input = 'interactive';
    }

    return $input;
  }

  /**
   * Makes it easier to print response when AJAX request succeeds.
   *
   * @param mixed $data
   * @since 1.6.0
   */
  public static function ajaxSuccess($data = NULL, $only_data = FALSE) {
    $response = array(
      'success' => TRUE
    );
    if ($data !== NULL) {
      $response['data'] = $data;

      // Pass data flatly to support old methods
      if ($only_data) {
        $response = $data;
      }
    }
    self::printJson($response);
  }

  /**
   * Makes it easier to print response when AJAX request fails.
   * Will exit after printing error.
   *
   * @param string $message A human readable error message
   * @param string $error_code An machine readable error code that a client
   * should be able to interpret
   * @param null|int $status_code Http response code
   * @param array [$details=null] Better description of the error and possible which action to take
   * @since 1.6.0
   */
  public static function ajaxError($message = NULL, $error_code = NULL, $status_code = NULL, $details = NULL) {
    $response = array(
      'success' => FALSE
    );
    if ($message !== NULL) {
      $response['message'] = $message;
    }

    if ($error_code !== NULL) {
      $response['errorCode'] = $error_code;
    }

    if ($details !== NULL) {
      $response['details'] = $details;
    }

    self::printJson($response, $status_code);
  }

  /**
   * Print JSON headers with UTF-8 charset and json encode response data.
   * Makes it easier to respond using JSON.
   *
   * @param mixed $data
   * @param null|int $status_code Http response code
   */
  private static function printJson($data, $status_code = NULL) {
    header('Cache-Control: no-cache');
    header('Content-type: application/json; charset=utf-8');
    print json_encode($data);
  }

  /**
   * Get a new H5P security token for the given action.
   *
   * @param string $action
   * @return string token
   */
  public static function createToken($action) {
    // Create and return token
    return self::hashToken($action, self::getTimeFactor());
  }

  /**
   * Create a time based number which is unique for each 12 hour.
   * @return int
   */
  private static function getTimeFactor() {
    return ceil(time() / (86400 / 2));
  }

  /**
   * Generate a unique hash string based on action, time and token
   *
   * @param string $action
   * @param int $time_factor
   * @return string
   */
  private static function hashToken($action, $time_factor) {
    if (!isset($_SESSION['h5p_token'])) {
      // Create an unique key which is used to create action tokens for this session.
      if (function_exists('random_bytes')) {
        $_SESSION['h5p_token'] = base64_encode(random_bytes(15));
      }
      else if (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['h5p_token'] = base64_encode(openssl_random_pseudo_bytes(15));
      }
      else {
        $_SESSION['h5p_token'] = uniqid('', TRUE);
      }
    }

    // Create hash and return
    return substr(hash('md5', $action . $time_factor . $_SESSION['h5p_token']), -16, 13);
  }

  /**
   * Verify if the given token is valid for the given action.
   *
   * @param string $action
   * @param string $token
   * @return boolean valid token
   */
  public static function validToken($action, $token) {
    // Get the timefactor
    $time_factor = self::getTimeFactor();

    // Check token to see if it's valid
    return $token === self::hashToken($action, $time_factor) || // Under 12 hours
           $token === self::hashToken($action, $time_factor - 1); // Between 12-24 hours
  }

  /**
   * Update content type cache
   *
   * @param object $postData Data sent to the hub
   *
   * @return bool|object Returns endpoint data if found, otherwise FALSE
   */
  public function updateContentTypeCache($postData = NULL) {
    $interface = $this->h5pF;

    // Make sure data is sent!
    if (!isset($postData) || !isset($postData['uuid'])) {
      return $this->fetchLibrariesMetadata();
    }

    $postData['current_cache'] = $this->h5pF->getOption('content_type_cache_updated_at', 0);

    $data = $interface->fetchExternalData(H5PHubEndpoints::createURL(H5PHubEndpoints::CONTENT_TYPES), $postData);

    if (! $this->h5pF->getOption('hub_is_enabled', TRUE)) {
      return TRUE;
    }

    // No data received
    if (!$data) {
      $interface->setErrorMessage(
        $interface->t("Couldn't communicate with the H5P Hub. Please try again later."),
        'failed-communicationg-with-hub'
      );
      return FALSE;
    }

    $json = json_decode($data);

    // No libraries received
    if (!isset($json->contentTypes) || empty($json->contentTypes)) {
      $interface->setErrorMessage(
        $interface->t('No content types were received from the H5P Hub. Please try again later.'),
        'no-content-types-from-hub'
      );
      return FALSE;
    }

    // Replace content type cache
    $interface->replaceContentTypeCache($json);

    // Inform of the changes and update timestamp
    $interface->setInfoMessage($interface->t('Library cache was successfully updated!'));
    $interface->setOption('content_type_cache_updated_at', time());
    return $data;
  }

  /**
   * Check if the current server setup is valid and set error messages
   *
   * @return object Setup object with errors and disable hub properties
   */
  public function checkSetupErrorMessage() {
    $setup = (object) array(
      'errors' => array(),
      'disable_hub' => FALSE
    );

    if (!class_exists('ZipArchive')) {
      $setup->errors[] = $this->h5pF->t('Your PHP version does not support ZipArchive.');
      $setup->disable_hub = TRUE;
    }

    if (!extension_loaded('mbstring')) {
      $setup->errors[] = $this->h5pF->t(
        'The mbstring PHP extension is not loaded. H5P needs this to function properly'
      );
      $setup->disable_hub = TRUE;
    }

    // Check php version >= 5.2
    $php_version = explode('.', phpversion());
    if ($php_version[0] < 5 || ($php_version[0] === 5 && $php_version[1] < 2)) {
      $setup->errors[] = $this->h5pF->t('Your PHP version is outdated. H5P requires version 5.2 to function properly. Version 5.6 or later is recommended.');
      $setup->disable_hub = TRUE;
    }

    // Check write access
    if (!$this->fs->hasWriteAccess()) {
      $setup->errors[] = $this->h5pF->t('A problem with the server write access was detected. Please make sure that your server can write to your data folder.');
      $setup->disable_hub = TRUE;
    }

    $max_upload_size = self::returnBytes(ini_get('upload_max_filesize'));
    $max_post_size   = self::returnBytes(ini_get('post_max_size'));
    $byte_threshold  = 5000000; // 5MB
    if ($max_upload_size < $byte_threshold) {
      $setup->errors[] =
        $this->h5pF->t('Your PHP max upload size is quite small. With your current setup, you may not upload files larger than %number MB. This might be a problem when trying to upload H5Ps, images and videos. Please consider to increase it to more than 5MB.', array('%number' => number_format($max_upload_size / 1024 / 1024, 2, '.', ' ')));
    }

    if ($max_post_size < $byte_threshold) {
      $setup->errors[] =
        $this->h5pF->t('Your PHP max post size is quite small. With your current setup, you may not upload files larger than %number MB. This might be a problem when trying to upload H5Ps, images and videos. Please consider to increase it to more than 5MB', array('%number' => number_format($max_upload_size / 1024 / 1024, 2, '.', ' ')));
    }

    if ($max_upload_size > $max_post_size) {
      $setup->errors[] =
        $this->h5pF->t('Your PHP max upload size is bigger than your max post size. This is known to cause issues in some installations.');
    }

    // Check SSL
    if (!extension_loaded('openssl')) {
      $setup->errors[] =
        $this->h5pF->t('Your server does not have SSL enabled. SSL should be enabled to ensure a secure connection with the H5P hub.');
      $setup->disable_hub = TRUE;
    }

    return $setup;
  }

  /**
   * Check that all H5P requirements for the server setup is met.
   */
  public function checkSetupForRequirements() {
    $setup = $this->checkSetupErrorMessage();

    $this->h5pF->setOption('hub_is_enabled', !$setup->disable_hub);
    if (!empty($setup->errors)) {
      foreach ($setup->errors as $err) {
        $this->h5pF->setErrorMessage($err);
      }
    }

    if ($setup->disable_hub) {
      // Inform how to re-enable hub
      $this->h5pF->setErrorMessage(
        $this->h5pF->t('H5P hub communication has been disabled because one or more H5P requirements failed.')
      );
      $this->h5pF->setErrorMessage(
        $this->h5pF->t('When you have revised your server setup you may re-enable H5P hub communication in H5P Settings.')
      );
    }
  }

  /**
   * Return bytes from php_ini string value
   *
   * @param string $val
   *
   * @return int|string
   */
  public static function returnBytes($val) {
    $val  = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $bytes = (int) $val;

    switch ($last) {
      case 'g':
        $bytes *= 1024;
      case 'm':
        $bytes *= 1024;
      case 'k':
        $bytes *= 1024;
    }

    return $bytes;
  }

  /**
   * Check if the current user has permission to update and install new
   * libraries.
   *
   * @param bool [$set] Optional, sets the permission
   * @return bool
   */
  public function mayUpdateLibraries($set = null) {
    static $can;

    if ($set !== null) {
      // Use value set
      $can = $set;
    }

    if ($can === null) {
      // Ask our framework
      $can = $this->h5pF->mayUpdateLibraries();
    }

    return $can;
  }

  /**
   * Provide localization for the Core JS
   * @return array
   */
  public function getLocalization() {
    return array(
      'fullscreen' => $this->h5pF->t('Fullscreen'),
      'disableFullscreen' => $this->h5pF->t('Disable fullscreen'),
      'download' => $this->h5pF->t('Download'),
      'copyrights' => $this->h5pF->t('Rights of use'),
      'embed' => $this->h5pF->t('Embed'),
      'size' => $this->h5pF->t('Size'),
      'showAdvanced' => $this->h5pF->t('Show advanced'),
      'hideAdvanced' => $this->h5pF->t('Hide advanced'),
      'advancedHelp' => $this->h5pF->t('Include this script on your website if you want dynamic sizing of the embedded content:'),
      'copyrightInformation' => $this->h5pF->t('Rights of use'),
      'close' => $this->h5pF->t('Close'),
      'title' => $this->h5pF->t('Title'),
      'author' => $this->h5pF->t('Author'),
      'year' => $this->h5pF->t('Year'),
      'source' => $this->h5pF->t('Source'),
      'license' => $this->h5pF->t('License'),
      'thumbnail' => $this->h5pF->t('Thumbnail'),
      'noCopyrights' => $this->h5pF->t('No copyright information available for this content.'),
      'downloadDescription' => $this->h5pF->t('Download this content as a H5P file.'),
      'copyrightsDescription' => $this->h5pF->t('View copyright information for this content.'),
      'embedDescription' => $this->h5pF->t('View the embed code for this content.'),
      'h5pDescription' => $this->h5pF->t('Visit H5P.org to check out more cool content.'),
      'contentChanged' => $this->h5pF->t('This content has changed since you last used it.'),
      'startingOver' => $this->h5pF->t("You'll be starting over."),
      'by' => $this->h5pF->t('by'),
      'showMore' => $this->h5pF->t('Show more'),
      'showLess' => $this->h5pF->t('Show less'),
      'subLevel' => $this->h5pF->t('Sublevel'),
      'confirmDialogHeader' => $this->h5pF->t('Confirm action'),
      'confirmDialogBody' => $this->h5pF->t('Please confirm that you wish to proceed. This action is not reversible.'),
      'cancelLabel' => $this->h5pF->t('Cancel'),
      'confirmLabel' => $this->h5pF->t('Confirm'),
      'licenseU' => $this->h5pF->t('Undisclosed'),
      'licenseCCBY' => $this->h5pF->t('Attribution'),
      'licenseCCBYSA' => $this->h5pF->t('Attribution-ShareAlike'),
      'licenseCCBYND' => $this->h5pF->t('Attribution-NoDerivs'),
      'licenseCCBYNC' => $this->h5pF->t('Attribution-NonCommercial'),
      'licenseCCBYNCSA' => $this->h5pF->t('Attribution-NonCommercial-ShareAlike'),
      'licenseCCBYNCND' => $this->h5pF->t('Attribution-NonCommercial-NoDerivs'),
      'licenseCC40' => $this->h5pF->t('4.0 International'),
      'licenseCC30' => $this->h5pF->t('3.0 Unported'),
      'licenseCC25' => $this->h5pF->t('2.5 Generic'),
      'licenseCC20' => $this->h5pF->t('2.0 Generic'),
      'licenseCC10' => $this->h5pF->t('1.0 Generic'),
      'licenseGPL' => $this->h5pF->t('General Public License'),
      'licenseV3' => $this->h5pF->t('Version 3'),
      'licenseV2' => $this->h5pF->t('Version 2'),
      'licenseV1' => $this->h5pF->t('Version 1'),
      'licensePD' => $this->h5pF->t('Public Domain'),
      'licenseCC010' => $this->h5pF->t('CC0 1.0 Universal (CC0 1.0) Public Domain Dedication'),
      'licensePDM' => $this->h5pF->t('Public Domain Mark'),
      'licenseC' => $this->h5pF->t('Copyright')
    );
  }
}