<?php
/**
 * Functions for validating basic types from H5P library semantics.
 * @property bool allowedStyles
 */
class H5PContentValidator {
  public $h5pF;
  public $h5pC;
  private $typeMap, $libraries, $dependencies, $nextWeight;
  private static $allowed_styleable_tags = array('span', 'p', 'div','h1','h2','h3', 'td');

  /**
   * Constructor for the H5PContentValidator
   *
   * @param object $H5PFramework
   *  The frameworks implementation of the H5PFrameworkInterface
   * @param object $H5PCore
   *  The main H5PCore instance
   */
  public function __construct($H5PFramework, $H5PCore) {
    $this->h5pF = $H5PFramework;
    $this->h5pC = $H5PCore;
    $this->typeMap = array(
      'text' => 'validateText',
      'number' => 'validateNumber',
      'boolean' => 'validateBoolean',
      'list' => 'validateList',
      'group' => 'validateGroup',
      'file' => 'validateFile',
      'image' => 'validateImage',
      'video' => 'validateVideo',
      'audio' => 'validateAudio',
      'select' => 'validateSelect',
      'library' => 'validateLibrary',
    );
    $this->nextWeight = 1;

    // Keep track of the libraries we load to avoid loading it multiple times.
    $this->libraries = array();

    // Keep track of all dependencies for the given content.
    $this->dependencies = array();
  }

  /**
   * Get the flat dependency tree.
   *
   * @return array
   */
  public function getDependencies() {
    return $this->dependencies;
  }

  /**
   * Validate given text value against text semantics.
   * @param $text
   * @param $semantics
   */
  public function validateText(&$text, $semantics) {
    if (!is_string($text)) {
      $text = '';
    }
    if (isset($semantics->tags)) {
      // Not testing for empty array allows us to use the 4 defaults without
      // specifying them in semantics.
      $tags = array_merge(array('div', 'span', 'p', 'br'), $semantics->tags);

      // Add related tags for table etc.
      if (in_array('table', $tags)) {
        $tags = array_merge($tags, array('tr', 'td', 'th', 'colgroup', 'thead', 'tbody', 'tfoot'));
      }
      if (in_array('b', $tags) && ! in_array('strong', $tags)) {
        $tags[] = 'strong';
      }
      if (in_array('i', $tags) && ! in_array('em', $tags)) {
        $tags[] = 'em';
      }
      if (in_array('ul', $tags) || in_array('ol', $tags) && ! in_array('li', $tags)) {
        $tags[] = 'li';
      }
      if (in_array('del', $tags) || in_array('strike', $tags) && ! in_array('s', $tags)) {
        $tags[] = 's';
      }

      // Determine allowed style tags
      $stylePatterns = array();
      // All styles must be start to end patterns (^...$)
      if (isset($semantics->font)) {
        if (isset($semantics->font->size) && $semantics->font->size) {
          $stylePatterns[] = '/^font-size: *[0-9.]+(em|px|%) *;?$/i';
        }
        if (isset($semantics->font->family) && $semantics->font->family) {
          $stylePatterns[] = '/^font-family: *[-a-z0-9," ]+;?$/i';
        }
        if (isset($semantics->font->color) && $semantics->font->color) {
          $stylePatterns[] = '/^color: *(#[a-f0-9]{3}[a-f0-9]{3}?|rgba?\([0-9, ]+\)) *;?$/i';
        }
        if (isset($semantics->font->background) && $semantics->font->background) {
          $stylePatterns[] = '/^background-color: *(#[a-f0-9]{3}[a-f0-9]{3}?|rgba?\([0-9, ]+\)) *;?$/i';
        }
        if (isset($semantics->font->spacing) && $semantics->font->spacing) {
          $stylePatterns[] = '/^letter-spacing: *[0-9.]+(em|px|%) *;?$/i';
        }
        if (isset($semantics->font->height) && $semantics->font->height) {
          $stylePatterns[] = '/^line-height: *[0-9.]+(em|px|%|) *;?$/i';
        }
      }

      // Alignment is allowed for all wysiwyg texts
      $stylePatterns[] = '/^text-align: *(center|left|right);?$/i';

      // Strip invalid HTML tags.
      $text = $this->filter_xss($text, $tags, $stylePatterns);
    }
    else {
      // Filter text to plain text.
      $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8', FALSE);
    }

    // Check if string is within allowed length
    if (isset($semantics->maxLength)) {
      if (!extension_loaded('mbstring')) {
        $this->h5pF->setErrorMessage($this->h5pF->t('The mbstring PHP extension is not loaded. H5P need this to function properly'), 'mbstring-unsupported');
      }
      else {
        $text = mb_substr($text, 0, $semantics->maxLength);
      }
    }

    // Check if string is according to optional regexp in semantics
    if (!($text === '' && isset($semantics->optional) && $semantics->optional) && isset($semantics->regexp)) {
      // Escaping '/' found in patterns, so that it does not break regexp fencing.
      $pattern = '/' . str_replace('/', '\\/', $semantics->regexp->pattern) . '/';
      $pattern .= isset($semantics->regexp->modifiers) ? $semantics->regexp->modifiers : '';
      if (preg_match($pattern, $text) === 0) {
        // Note: explicitly ignore return value FALSE, to avoid removing text
        // if regexp is invalid...
        $this->h5pF->setErrorMessage($this->h5pF->t('Provided string is not valid according to regexp in semantics. (value: "%value", regexp: "%regexp")', array('%value' => $text, '%regexp' => $pattern)), 'semantics-invalid-according-regexp');
        $text = '';
      }
    }
  }

  /**
   * Validates content files
   *
   * @param string $contentPath
   *  The path containing content files to validate.
   * @param bool $isLibrary
   * @return bool TRUE if all files are valid
   * TRUE if all files are valid
   * FALSE if one or more files fail validation. Error message should be set accordingly by validator.
   */
  public function validateContentFiles($contentPath, $isLibrary = FALSE) {
    if ($this->h5pC->disableFileCheck === TRUE) {
      return TRUE;
    }

    // Scan content directory for files, recurse into sub directories.
    $files = array_diff(scandir($contentPath), array('.','..'));
    $valid = TRUE;
    $whitelist = $this->h5pF->getWhitelist($isLibrary, H5PCore::$defaultContentWhitelist, H5PCore::$defaultLibraryWhitelistExtras);

    $wl_regex = '/\.(' . preg_replace('/ +/i', '|', preg_quote($whitelist)) . ')$/i';

    foreach ($files as $file) {
      $filePath = $contentPath . DIRECTORY_SEPARATOR . $file;
      if (is_dir($filePath)) {
        $valid = $this->validateContentFiles($filePath, $isLibrary) && $valid;
      }
      else {
        // Snipped from drupal 6 "file_validate_extensions".  Using own code
        // to avoid 1. creating a file-like object just to test for the known
        // file name, 2. testing against a returned error array that could
        // never be more than 1 element long anyway, 3. recreating the regex
        // for every file.
        if (!extension_loaded('mbstring')) {
          $this->h5pF->setErrorMessage($this->h5pF->t('The mbstring PHP extension is not loaded. H5P need this to function properly'), 'mbstring-unsupported');
          $valid = FALSE;
        }
        else if (!preg_match($wl_regex, mb_strtolower($file))) {
          $this->h5pF->setErrorMessage($this->h5pF->t('File "%filename" not allowed. Only files with the following extensions are allowed: %files-allowed.', array('%filename' => $file, '%files-allowed' => $whitelist)), 'not-in-whitelist');
          $valid = FALSE;
        }
      }
    }
    return $valid;
  }

  /**
   * Validate given value against number semantics
   * @param $number
   * @param $semantics
   */
  public function validateNumber(&$number, $semantics) {
    // Validate that $number is indeed a number
    if (!is_numeric($number)) {
      $number = 0;
    }
    // Check if number is within valid bounds. Move within bounds if not.
    if (isset($semantics->min) && $number < $semantics->min) {
      $number = $semantics->min;
    }
    if (isset($semantics->max) && $number > $semantics->max) {
      $number = $semantics->max;
    }
    // Check if number is within allowed bounds even if step value is set.
    if (isset($semantics->step)) {
      $testNumber = $number - (isset($semantics->min) ? $semantics->min : 0);
      $rest = $testNumber % $semantics->step;
      if ($rest !== 0) {
        $number -= $rest;
      }
    }
    // Check if number has proper number of decimals.
    if (isset($semantics->decimals)) {
      $number = round($number, $semantics->decimals);
    }
  }

  /**
   * Validate given value against boolean semantics
   * @param $bool
   * @return bool
   */
  public function validateBoolean(&$bool) {
    return is_bool($bool);
  }

  /**
   * Validate select values
   * @param $select
   * @param $semantics
   */
  public function validateSelect(&$select, $semantics) {
    $optional = isset($semantics->optional) && $semantics->optional;
    $strict = FALSE;
    if (isset($semantics->options) && !empty($semantics->options)) {
      // We have a strict set of options to choose from.
      $strict = TRUE;
      $options = array();
      foreach ($semantics->options as $option) {
        $options[$option->value] = TRUE;
      }
    }

    if (isset($semantics->multiple) && $semantics->multiple) {
      // Multi-choice generates array of values. Test each one against valid
      // options, if we are strict.  First make sure we are working on an
      // array.
      if (!is_array($select)) {
        $select = array($select);
      }

      foreach ($select as $key => &$value) {
        if ($strict && !$optional && !isset($options[$value])) {
          $this->h5pF->setErrorMessage($this->h5pF->t('Invalid selected option in multi-select.'));
          unset($select[$key]);
        }
        else {
          $select[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8', FALSE);
        }
      }
    }
    else {
      // Single mode.  If we get an array in here, we chop off the first
      // element and use that instead.
      if (is_array($select)) {
        $select = $select[0];
      }

      if ($strict && !$optional && !isset($options[$select])) {
        $this->h5pF->setErrorMessage($this->h5pF->t('Invalid selected option in select.'));
        $select = $semantics->options[0]->value;
      }
      $select = htmlspecialchars($select, ENT_QUOTES, 'UTF-8', FALSE);
    }
  }

  /**
   * Validate given list value against list semantics.
   * Will recurse into validating each item in the list according to the type.
   * @param $list
   * @param $semantics
   */
  public function validateList(&$list, $semantics) {
    $field = $semantics->field;
    $function = $this->typeMap[$field->type];

    // Check that list is not longer than allowed length. We do this before
    // iterating to avoid unnecessary work.
    if (isset($semantics->max)) {
      array_splice($list, $semantics->max);
    }

    if (!is_array($list)) {
      $list = array();
    }

    // Validate each element in list.
    foreach ($list as $key => &$value) {
      if (!is_int($key)) {
        array_splice($list, $key, 1);
        continue;
      }
      $this->$function($value, $field);
      if ($value === NULL) {
        array_splice($list, $key, 1);
      }
    }

    if (count($list) === 0) {
      $list = NULL;
    }
  }

  /**
   * Validate a file like object, such as video, image, audio and file.
   * @param $file
   * @param $semantics
   * @param array $typeValidKeys
   */
  private function _validateFilelike(&$file, $semantics, $typeValidKeys = array()) {
    // Do not allow to use files from other content folders.
    $matches = array();
    if (preg_match($this->h5pC->relativePathRegExp, $file->path, $matches)) {
      $file->path = $matches[5];
    }

    // Remove temporary files suffix
    if (substr($file->path, -4, 4) === '#tmp') {
      $file->path = substr($file->path, 0, strlen($file->path) - 4);
    }

    // Make sure path and mime does not have any special chars
    $file->path = htmlspecialchars($file->path, ENT_QUOTES, 'UTF-8', FALSE);
    if (isset($file->mime)) {
      $file->mime = htmlspecialchars($file->mime, ENT_QUOTES, 'UTF-8', FALSE);
    }

    // Remove attributes that should not exist, they may contain JSON escape
    // code.
    $validKeys = array_merge(array('path', 'mime', 'copyright'), $typeValidKeys);
    if (isset($semantics->extraAttributes)) {
      $validKeys = array_merge($validKeys, $semantics->extraAttributes); // TODO: Validate extraAttributes
    }
    $this->filterParams($file, $validKeys);

    if (isset($file->width)) {
      $file->width = intval($file->width);
    }

    if (isset($file->height)) {
      $file->height = intval($file->height);
    }

    if (isset($file->codecs)) {
      $file->codecs = htmlspecialchars($file->codecs, ENT_QUOTES, 'UTF-8', FALSE);
    }

    if (isset($file->quality)) {
      if (!is_object($file->quality) || !isset($file->quality->level) || !isset($file->quality->label)) {
        unset($file->quality);
      }
      else {
        $this->filterParams($file->quality, array('level', 'label'));
        $file->quality->level = intval($file->quality->level);
        $file->quality->label = htmlspecialchars($file->quality->label, ENT_QUOTES, 'UTF-8', FALSE);
      }
    }

    if (isset($file->copyright)) {
      $this->validateGroup($file->copyright, $this->getCopyrightSemantics());
      // TODO: We'll need to do something here about getMetadataSemantics() if we change the widgets
    }
  }

  /**
   * Validate given file data
   * @param $file
   * @param $semantics
   */
  public function validateFile(&$file, $semantics) {
    $this->_validateFilelike($file, $semantics);
  }

  /**
   * Validate given image data
   * @param $image
   * @param $semantics
   */
  public function validateImage(&$image, $semantics) {
    $this->_validateFilelike($image, $semantics, array('width', 'height', 'originalImage'));
  }

  /**
   * Validate given video data
   * @param $video
   * @param $semantics
   */
  public function validateVideo(&$video, $semantics) {
    foreach ($video as &$variant) {
      $this->_validateFilelike($variant, $semantics, array('width', 'height', 'codecs', 'quality'));
    }
  }

  /**
   * Validate given audio data
   * @param $audio
   * @param $semantics
   */
  public function validateAudio(&$audio, $semantics) {
    foreach ($audio as &$variant) {
      $this->_validateFilelike($variant, $semantics);
    }
  }

  /**
   * Validate given group value against group semantics.
   * Will recurse into validating each group member.
   * @param $group
   * @param $semantics
   * @param bool $flatten
   */
  public function validateGroup(&$group, $semantics, $flatten = TRUE) {
    // Groups with just one field are compressed in the editor to only output
    // the child content. (Exemption for fake groups created by
    // "validateBySemantics" above)
    $function = null;
    $field = null;

    $isSubContent = isset($semantics->isSubContent) && $semantics->isSubContent === TRUE;

    if (count($semantics->fields) == 1 && $flatten && !$isSubContent) {
      $field = $semantics->fields[0];
      $function = $this->typeMap[$field->type];
      $this->$function($group, $field);
    }
    else {
      foreach ($group as $key => &$value) {
        // If subContentId is set, keep value
        if($isSubContent && ($key == 'subContentId')){
          continue;
        }

        // Find semantics for name=$key
        $found = FALSE;
        foreach ($semantics->fields as $field) {
          if ($field->name == $key) {
            if (isset($semantics->optional) && $semantics->optional) {
              $field->optional = TRUE;
            }
            $function = $this->typeMap[$field->type];
            $found = TRUE;
            break;
          }
        }
        if ($found) {
          if ($function) {
            $this->$function($value, $field);
            if ($value === NULL) {
              unset($group->$key);
            }
          }
          else {
            // We have a field type in semantics for which we don't have a
            // known validator.
            $this->h5pF->setErrorMessage($this->h5pF->t('H5P internal error: unknown content type "@type" in semantics. Removing content!', array('@type' => $field->type)), 'semantics-unknown-type');
            unset($group->$key);
          }
        }
        else {
          // If validator is not found, something exists in content that does
          // not have a corresponding semantics field. Remove it.
          // $this->h5pF->setErrorMessage($this->h5pF->t('H5P internal error: no validator exists for @key', array('@key' => $key)));
          unset($group->$key);
        }
      }
    }
    if (!(isset($semantics->optional) && $semantics->optional)) {
      if ($group === NULL) {
        // Error no value. Errors aren't printed...
        return;
      }
      foreach ($semantics->fields as $field) {
        if (!(isset($field->optional) && $field->optional)) {
          // Check if field is in group.
          if (! property_exists($group, $field->name)) {
            //$this->h5pF->setErrorMessage($this->h5pF->t('No value given for mandatory field ' . $field->name));
          }
        }
      }
    }
  }

  /**
   * Validate given library value against library semantics.
   * Check if provided library is within allowed options.
   *
   * Will recurse into validating the library's semantics too.
   * @param $value
   * @param $semantics
   */
  public function validateLibrary(&$value, $semantics) {
    if (!isset($value->library)) {
      $value = NULL;
      return;
    }

    // Check for array of objects or array of strings
    if (is_object($semantics->options[0])) {
      $getLibraryNames = function ($item) {
        return $item->name;
      };
      $libraryNames = array_map($getLibraryNames, $semantics->options);
    }
    else {
      $libraryNames = $semantics->options;
    }

    if (!in_array($value->library, $libraryNames)) {
      $message = NULL;
      // Create an understandable error message:
      $machineNameArray = explode(' ', $value->library);
      $machineName = $machineNameArray[0];
      foreach ($libraryNames as $semanticsLibrary) {
        $semanticsMachineNameArray = explode(' ', $semanticsLibrary);
        $semanticsMachineName = $semanticsMachineNameArray[0];
        if ($machineName === $semanticsMachineName) {
          // Using the wrong version of the library in the content
          $message = $this->h5pF->t('The version of the H5P library %machineName used in this content is not valid. Content contains %contentLibrary, but it should be %semanticsLibrary.', array(
            '%machineName' => $machineName,
            '%contentLibrary' => $value->library,
            '%semanticsLibrary' => $semanticsLibrary
          ));
          break;
        }
      }

      // Using a library in content that is not present at all in semantics
      if ($message === NULL) {
        $message = $this->h5pF->t('The H5P library %library used in the content is not valid', array(
          '%library' => $value->library
        ));
      }

      $this->h5pF->setErrorMessage($message);
      $value = NULL;
      return;
    }

    if (!isset($this->libraries[$value->library])) {
      $libSpec = H5PCore::libraryFromString($value->library);
      $library = $this->h5pC->loadLibrary($libSpec['machineName'], $libSpec['majorVersion'], $libSpec['minorVersion']);
      $library['semantics'] = $this->h5pC->loadLibrarySemantics($libSpec['machineName'], $libSpec['majorVersion'], $libSpec['minorVersion']);
      $this->libraries[$value->library] = $library;
    }
    else {
      $library = $this->libraries[$value->library];
    }

    $this->validateGroup($value->params, (object) array(
      'type' => 'group',
      'fields' => $library['semantics'],
    ), FALSE);
    $validKeys = array('library', 'params', 'subContentId', 'metadata');
    if (isset($semantics->extraAttributes)) {
      $validKeys = array_merge($validKeys, $semantics->extraAttributes);
    }

    $this->filterParams($value, $validKeys);
    if (isset($value->subContentId) && ! preg_match('/^\{?[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\}?$/', $value->subContentId)) {
      unset($value->subContentId);
    }

    // Find all dependencies for this library
    $depKey = 'preloaded-' . $library['machineName'];
    if (!isset($this->dependencies[$depKey])) {
      $this->dependencies[$depKey] = array(
        'library' => $library,
        'type' => 'preloaded'
      );

      $this->nextWeight = $this->h5pC->findLibraryDependencies($this->dependencies, $library, $this->nextWeight);
      $this->dependencies[$depKey]['weight'] = $this->nextWeight++;
    }
  }

  /**
   * Check params for a whitelist of allowed properties
   *
   * @param array/object $params
   * @param array $whitelist
   */
  public function filterParams(&$params, $whitelist) {
    foreach ($params as $key => $value) {
      if (!in_array($key, $whitelist)) {
        unset($params->{$key});
      }
    }
  }

  // XSS filters copied from drupal 7 common.inc. Some modifications done to
  // replace Drupal one-liner functions with corresponding flat PHP.

  /**
   * Filters HTML to prevent cross-site-scripting (XSS) vulnerabilities.
   *
   * Based on kses by Ulf Harnhammar, see http://sourceforge.net/projects/kses.
   * For examples of various XSS attacks, see: http://ha.ckers.org/xss.html.
   *
   * This code does four things:
   * - Removes characters and constructs that can trick browsers.
   * - Makes sure all HTML entities are well-formed.
   * - Makes sure all HTML tags and attributes are well-formed.
   * - Makes sure no HTML tags contain URLs with a disallowed protocol (e.g.
   *   javascript:).
   *
   * @param $string
   *   The string with raw HTML in it. It will be stripped of everything that can
   *   cause an XSS attack.
   * @param array $allowed_tags
   *   An array of allowed tags.
   *
   * @param bool $allowedStyles
   * @return mixed|string An XSS safe version of $string, or an empty string if $string is not
   * An XSS safe version of $string, or an empty string if $string is not
   * valid UTF-8.
   * @ingroup sanitation
   */
  private function filter_xss($string, $allowed_tags = array('a', 'em', 'strong', 'cite', 'blockquote', 'code', 'ul', 'ol', 'li', 'dl', 'dt', 'dd'), $allowedStyles = FALSE) {
    if (strlen($string) == 0) {
      return $string;
    }
    // Only operate on valid UTF-8 strings. This is necessary to prevent cross
    // site scripting issues on Internet Explorer 6. (Line copied from
    // drupal_validate_utf8)
    if (preg_match('/^./us', $string) != 1) {
      return '';
    }

    $this->allowedStyles = $allowedStyles;

    // Store the text format.
    $this->_filter_xss_split($allowed_tags, TRUE);
    // Remove NULL characters (ignored by some browsers).
    $string = str_replace(chr(0), '', $string);
    // Remove Netscape 4 JS entities.
    $string = preg_replace('%&\s*\{[^}]*(\}\s*;?|$)%', '', $string);

    // Defuse all HTML entities.
    $string = str_replace('&', '&amp;', $string);
    // Change back only well-formed entities in our whitelist:
    // Decimal numeric entities.
    $string = preg_replace('/&amp;#([0-9]+;)/', '&#\1', $string);
    // Hexadecimal numeric entities.
    $string = preg_replace('/&amp;#[Xx]0*((?:[0-9A-Fa-f]{2})+;)/', '&#x\1', $string);
    // Named entities.
    $string = preg_replace('/&amp;([A-Za-z][A-Za-z0-9]*;)/', '&\1', $string);
    return preg_replace_callback('%
      (
      <(?=[^a-zA-Z!/])  # a lone <
      |                 # or
      <!--.*?-->        # a comment
      |                 # or
      <[^>]*(>|$)       # a string that starts with a <, up until the > or the end of the string
      |                 # or
      >                 # just a >
      )%x', array($this, '_filter_xss_split'), $string);
  }

  /**
   * Processes an HTML tag.
   *
   * @param $m
   *   An array with various meaning depending on the value of $store.
   *   If $store is TRUE then the array contains the allowed tags.
   *   If $store is FALSE then the array has one element, the HTML tag to process.
   * @param bool $store
   *   Whether to store $m.
   * @return string If the element isn't allowed, an empty string. Otherwise, the cleaned up
   * If the element isn't allowed, an empty string. Otherwise, the cleaned up
   * version of the HTML element.
   */
  private function _filter_xss_split($m, $store = FALSE) {
    static $allowed_html;

    if ($store) {
      $allowed_html = array_flip($m);
      return $allowed_html;
    }

    $string = $m[1];

    if (substr($string, 0, 1) != '<') {
      // We matched a lone ">" character.
      return '&gt;';
    }
    elseif (strlen($string) == 1) {
      // We matched a lone "<" character.
      return '&lt;';
    }

    if (!preg_match('%^<\s*(/\s*)?([a-zA-Z0-9\-]+)([^>]*)>?|(<!--.*?-->)$%', $string, $matches)) {
      // Seriously malformed.
      return '';
    }

    $slash = trim($matches[1]);
    $elem = &$matches[2];
    $attrList = &$matches[3];
    $comment = &$matches[4];

    if ($comment) {
      $elem = '!--';
    }

    if (!isset($allowed_html[strtolower($elem)])) {
      // Disallowed HTML element.
      return '';
    }

    if ($comment) {
      return $comment;
    }

    if ($slash != '') {
      return "</$elem>";
    }

    // Is there a closing XHTML slash at the end of the attributes?
    $attrList = preg_replace('%(\s?)/\s*$%', '\1', $attrList, -1, $count);
    $xhtml_slash = $count ? ' /' : '';

    // Clean up attributes.

    $attr2 = implode(' ', $this->_filter_xss_attributes($attrList, (in_array($elem, self::$allowed_styleable_tags) ? $this->allowedStyles : FALSE)));
    $attr2 = preg_replace('/[<>]/', '', $attr2);
    $attr2 = strlen($attr2) ? ' ' . $attr2 : '';

    return "<$elem$attr2$xhtml_slash>";
  }

  /**
   * Processes a string of HTML attributes.
   *
   * @param $attr
   * @param array|bool|object $allowedStyles
   * @return array Cleaned up version of the HTML attributes.
   * Cleaned up version of the HTML attributes.
   */
  private function _filter_xss_attributes($attr, $allowedStyles = FALSE) {
    $attrArr = array();
    $mode = 0;
    $attrName = '';
    $skip = false;

    while (strlen($attr) != 0) {
      // Was the last operation successful?
      $working = 0;
      switch ($mode) {
        case 0:
          // Attribute name, href for instance.
          if (preg_match('/^([-a-zA-Z]+)/', $attr, $match)) {
            $attrName = strtolower($match[1]);
            $skip = ($attrName == 'style' || substr($attrName, 0, 2) == 'on');
            $working = $mode = 1;
            $attr = preg_replace('/^[-a-zA-Z]+/', '', $attr);
          }
          break;

        case 1:
          // Equals sign or valueless ("selected").
          if (preg_match('/^\s*=\s*/', $attr)) {
            $working = 1; $mode = 2;
            $attr = preg_replace('/^\s*=\s*/', '', $attr);
            break;
          }

          if (preg_match('/^\s+/', $attr)) {
            $working = 1; $mode = 0;
            if (!$skip) {
              $attrArr[] = $attrName;
            }
            $attr = preg_replace('/^\s+/', '', $attr);
          }
          break;

        case 2:
          // Attribute value, a URL after href= for instance.
          if (preg_match('/^"([^"]*)"(\s+|$)/', $attr, $match)) {
            if ($allowedStyles && $attrName === 'style') {
              // Allow certain styles
              foreach ($allowedStyles as $pattern) {
                if (preg_match($pattern, $match[1])) {
                  // All patterns are start to end patterns, and CKEditor adds one span per style
                  $attrArr[] = 'style="' . $match[1] . '"';
                  break;
                }
              }
              break;
            }

            $thisVal = $this->filter_xss_bad_protocol($match[1]);

            if (!$skip) {
              $attrArr[] = "$attrName=\"$thisVal\"";
            }
            $working = 1;
            $mode = 0;
            $attr = preg_replace('/^"[^"]*"(\s+|$)/', '', $attr);
            break;
          }

          if (preg_match("/^'([^']*)'(\s+|$)/", $attr, $match)) {
            $thisVal = $this->filter_xss_bad_protocol($match[1]);

            if (!$skip) {
              $attrArr[] = "$attrName='$thisVal'";
            }
            $working = 1; $mode = 0;
            $attr = preg_replace("/^'[^']*'(\s+|$)/", '', $attr);
            break;
          }

          if (preg_match("%^([^\s\"']+)(\s+|$)%", $attr, $match)) {
            $thisVal = $this->filter_xss_bad_protocol($match[1]);

            if (!$skip) {
              $attrArr[] = "$attrName=\"$thisVal\"";
            }
            $working = 1; $mode = 0;
            $attr = preg_replace("%^[^\s\"']+(\s+|$)%", '', $attr);
          }
          break;
      }

      if ($working == 0) {
        // Not well formed; remove and try again.
        $attr = preg_replace('/
          ^
          (
          "[^"]*("|$)     # - a string that starts with a double quote, up until the next double quote or the end of the string
          |               # or
          \'[^\']*(\'|$)| # - a string that starts with a quote, up until the next quote or the end of the string
          |               # or
          \S              # - a non-whitespace character
          )*              # any number of the above three
          \s*             # any number of whitespaces
          /x', '', $attr);
        $mode = 0;
      }
    }

    // The attribute list ends with a valueless attribute like "selected".
    if ($mode == 1 && !$skip) {
      $attrArr[] = $attrName;
    }
    return $attrArr;
  }

// TODO: Remove Drupal related stuff in docs.

  /**
   * Processes an HTML attribute value and strips dangerous protocols from URLs.
   *
   * @param $string
   *   The string with the attribute value.
   * @param bool $decode
   *   (deprecated) Whether to decode entities in the $string. Set to FALSE if the
   *   $string is in plain text, TRUE otherwise. Defaults to TRUE. This parameter
   *   is deprecated and will be removed in Drupal 8. To process a plain-text URI,
   *   call _strip_dangerous_protocols() or check_url() instead.
   * @return string Cleaned up and HTML-escaped version of $string.
   * Cleaned up and HTML-escaped version of $string.
   */
  private function filter_xss_bad_protocol($string, $decode = TRUE) {
    // Get the plain text representation of the attribute value (i.e. its meaning).
    // @todo Remove the $decode parameter in Drupal 8, and always assume an HTML
    //   string that needs decoding.
    if ($decode) {
      $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($this->_strip_dangerous_protocols($string), ENT_QUOTES, 'UTF-8', FALSE);
  }

  /**
   * Strips dangerous protocols (e.g. 'javascript:') from a URI.
   *
   * This function must be called for all URIs within user-entered input prior
   * to being output to an HTML attribute value. It is often called as part of
   * check_url() or filter_xss(), but those functions return an HTML-encoded
   * string, so this function can be called independently when the output needs to
   * be a plain-text string for passing to t(), l(), drupal_attributes(), or
   * another function that will call check_plain() separately.
   *
   * @param $uri
   *   A plain-text URI that might contain dangerous protocols.
   * @return string A plain-text URI stripped of dangerous protocols. As with all plain-text
   * A plain-text URI stripped of dangerous protocols. As with all plain-text
   * strings, this return value must not be output to an HTML page without
   * check_plain() being called on it. However, it can be passed to functions
   * expecting plain-text strings.
   * @see check_url()
   */
  private function _strip_dangerous_protocols($uri) {
    static $allowed_protocols;

    if (!isset($allowed_protocols)) {
      $allowed_protocols = array_flip(array('ftp', 'http', 'https', 'mailto'));
    }

    // Iteratively remove any invalid protocol found.
    do {
      $before = $uri;
      $colonPos = strpos($uri, ':');
      if ($colonPos > 0) {
        // We found a colon, possibly a protocol. Verify.
        $protocol = substr($uri, 0, $colonPos);
        // If a colon is preceded by a slash, question mark or hash, it cannot
        // possibly be part of the URL scheme. This must be a relative URL, which
        // inherits the (safe) protocol of the base document.
        if (preg_match('![/?#]!', $protocol)) {
          break;
        }
        // Check if this is a disallowed protocol. Per RFC2616, section 3.2.3
        // (URI Comparison) scheme comparison must be case-insensitive.
        if (!isset($allowed_protocols[strtolower($protocol)])) {
          $uri = substr($uri, $colonPos + 1);
        }
      }
    } while ($before != $uri);

    return $uri;
  }

  public function getMetadataSemantics() {
    static $semantics;

    $cc_versions = array(
      (object) array(
        'value' => '4.0',
        'label' => $this->h5pF->t('4.0 International')
      ),
      (object) array(
        'value' => '3.0',
        'label' => $this->h5pF->t('3.0 Unported')
      ),
      (object) array(
        'value' => '2.5',
        'label' => $this->h5pF->t('2.5 Generic')
      ),
      (object) array(
        'value' => '2.0',
        'label' => $this->h5pF->t('2.0 Generic')
      ),
      (object) array(
        'value' => '1.0',
        'label' => $this->h5pF->t('1.0 Generic')
      )
    );

    $semantics = (object) array(
      (object) array(
        'name' => 'copyright',
        'type' => 'group',
        'label' => $this->h5pF->t('Copyright information'),
        'fields' => array(
          (object) array(
            'name' => 'title',
            'type' => 'text',
            'label' => $this->h5pF->t('Title'),
            'placeholder' => 'La Gioconda'
          ),
          (object) array(
            'name' => 'license',
            'type' => 'select',
            'label' => $this->h5pF->t('License'),
            'default' => 'U',
            'options' => array(
              (object) array(
                'value' => 'U',
                'label' => $this->h5pF->t('Undisclosed')
              ),
              (object) array(
                'type' => 'optgroup',
                'label' => $this->h5pF->t('Creative Commons'),
                'options' => [
                  (object) array(
                    'value' => 'CC BY',
                    'label' => $this->h5pF->t('Attribution (CC BY)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC BY-SA',
                    'label' => $this->h5pF->t('Attribution-ShareAlike (CC BY-SA)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC BY-ND',
                    'label' => $this->h5pF->t('Attribution-NoDerivs (CC BY-ND)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC BY-NC',
                    'label' => $this->h5pF->t('Attribution-NonCommercial (CC BY-NC)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC BY-NC-SA',
                    'label' => $this->h5pF->t('Attribution-NonCommercial-ShareAlike (CC BY-NC-SA)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC BY-NC-ND',
                    'label' => $this->h5pF->t('Attribution-NonCommercial-NoDerivs (CC BY-NC-ND)'),
                    'versions' => $cc_versions
                  ),
                  (object) array(
                    'value' => 'CC0 1.0',
                    'label' => $this->h5pF->t('Public Domain Dedication (CC0)')
                  ),
                  (object) array(
                    'value' => 'CC PDM',
                    'label' => $this->h5pF->t('Public Domain Mark (PDM)')
                  ),
                ]
              ),
              (object) array(
                'value' => 'GNU GPL',
                'label' => $this->h5pF->t('General Public License v3')
              ),
              (object) array(
                'value' => 'PD',
                'label' => $this->h5pF->t('Public Domain')
              ),
              (object) array(
                'value' => 'ODC PDDL',
                'label' => $this->h5pF->t('Public Domain Dedication and Licence')
              ),
              (object) array(
                'value' => 'C',
                'label' => $this->h5pF->t('Copyright')
              )
            )
          ),
          (object) array(
            'name' => 'licenseVersion',
            'type' => 'select',
            'label' => $this->h5pF->t('License Version'),
            'options' => array(),
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'yearFrom',
            'type' => 'number',
            'label' => $this->h5pF->t('Years (from)'),
            'placeholder' => '1991',
            'min' => '-9999',
            'max' => '9999',
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'yearTo',
            'type' => 'number',
            'label' => $this->h5pF->t('Years (to)'),
            'placeholder' => '1992',
            'min' => '-9999',
            'max' => '9999',
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'source',
            'type' => 'text',
            'label' => $this->h5pF->t('Source'),
            'placeholder' => 'https://',
            'optional' => TRUE
          )
        )
      ),
      (object) array(
        'name' => 'authorWidget',
        'type' => 'group',
        'fields'=> array(
          (object) array(
            'label' => $this->h5pF->t("Author's name"),
            'name' => "name",
            'optional' => TRUE,
            'type' => "text"
          ),
          (object) array(
            'name' => 'role',
            'type' => 'select',
            'label' => $this->h5pF->t("Author's role"),
            'default' => 'Author',
            'options' => array(
              (object) array(
                'value' => 'Author',
                'label' => $this->h5pF->t('Author')
              ),
              (object) array(
                'value' => 'Editor',
                'label' => $this->h5pF->t('Editor')
              ),
              (object) array(
                'value' => 'Licensee',
                'label' => $this->h5pF->t('Licensee')
              ),
              (object) array(
                'value' => 'Originator',
                'label' => $this->h5pF->t('Originator')
              )
            )
          )
        )
      ),
      (object) array(
        'name' => 'licenseExtras',
        'type' => 'textarea',
        'label' => $this->h5pF->t('License Extras'),
        'optional' => TRUE,
        'description' => $this->h5pF->t('Any additional information about the license')
      ),
      (object) array(
        'name' => 'changeLog',
        'type' => 'group',
        'expanded' => FALSE,
        'label' => $this->h5pF->t('Change Log'),
        'fields' => array(
          (object) array (
            'name' => 'changeLogForm',
            'type' => 'group',
            'label' => $this->h5pF->t('Question'),
            'expanded' => TRUE,
            'fields' => array(
              (object) array(
                'name' => 'date',
                'type' => 'text',
                'label' => $this->h5pF->t('Date'),
                'optional' => TRUE
              ),
              (object) array(
                'name' => 'author',
                'type' => 'text',
                'label' => $this->h5pF->t('Changed by'),
                'optional' => TRUE
              ),
              (object) array(
                'name' => 'log',
                'type' => 'textarea',
                'label' => $this->h5pF->t('Description of change'),
                'placeholder' => $this->h5pF->t('Photo cropped, text changed, etc.'),
                'optional' => TRUE
              )
            )
          )
        )
      ),
      (object) array(
        'name' => 'authorComments',
        'label' => $this->h5pF->t('Additional Information'),
        'type' => 'group',
        'expanded' => FALSE,
        'fields' => array(
          (object) array (
            'name' => 'authorComments',
            'type' => 'textarea',
            'label' => $this->h5pF->t('Author comments'),
            'description' => $this->h5pF->t('Comments for the editor of the content (This text will not be published as a part of copyright info)'),
            'optional' => TRUE
          )
        )
      )
    );

    return $semantics;
  }

  public function getCopyrightSemantics() {
    static $semantics;

    if ($semantics === NULL) {
      $cc_versions = array(
        (object) array(
          'value' => '4.0',
          'label' => $this->h5pF->t('4.0 International')
        ),
        (object) array(
          'value' => '3.0',
          'label' => $this->h5pF->t('3.0 Unported')
        ),
        (object) array(
          'value' => '2.5',
          'label' => $this->h5pF->t('2.5 Generic')
        ),
        (object) array(
          'value' => '2.0',
          'label' => $this->h5pF->t('2.0 Generic')
        ),
        (object) array(
          'value' => '1.0',
          'label' => $this->h5pF->t('1.0 Generic')
        )
      );

      $semantics = (object) array(
        'name' => 'copyright',
        'type' => 'group',
        'label' => $this->h5pF->t('Copyright information'),
        'fields' => array(
          (object) array(
            'name' => 'title',
            'type' => 'text',
            'label' => $this->h5pF->t('Title'),
            'placeholder' => 'La Gioconda',
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'author',
            'type' => 'text',
            'label' => $this->h5pF->t('Author'),
            'placeholder' => 'Leonardo da Vinci',
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'year',
            'type' => 'text',
            'label' => $this->h5pF->t('Year(s)'),
            'placeholder' => '1503 - 1517',
            'optional' => TRUE
          ),
          (object) array(
            'name' => 'source',
            'type' => 'text',
            'label' => $this->h5pF->t('Source'),
            'placeholder' => 'http://en.wikipedia.org/wiki/Mona_Lisa',
            'optional' => true,
            'regexp' => (object) array(
              'pattern' => '^http[s]?://.+',
              'modifiers' => 'i'
            )
          ),
          (object) array(
            'name' => 'license',
            'type' => 'select',
            'label' => $this->h5pF->t('License'),
            'default' => 'U',
            'options' => array(
              (object) array(
                'value' => 'U',
                'label' => $this->h5pF->t('Undisclosed')
              ),
              (object) array(
                'value' => 'CC BY',
                'label' => $this->h5pF->t('Attribution'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'CC BY-SA',
                'label' => $this->h5pF->t('Attribution-ShareAlike'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'CC BY-ND',
                'label' => $this->h5pF->t('Attribution-NoDerivs'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'CC BY-NC',
                'label' => $this->h5pF->t('Attribution-NonCommercial'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'CC BY-NC-SA',
                'label' => $this->h5pF->t('Attribution-NonCommercial-ShareAlike'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'CC BY-NC-ND',
                'label' => $this->h5pF->t('Attribution-NonCommercial-NoDerivs'),
                'versions' => $cc_versions
              ),
              (object) array(
                'value' => 'GNU GPL',
                'label' => $this->h5pF->t('General Public License'),
                'versions' => array(
                  (object) array(
                    'value' => 'v3',
                    'label' => $this->h5pF->t('Version 3')
                  ),
                  (object) array(
                    'value' => 'v2',
                    'label' => $this->h5pF->t('Version 2')
                  ),
                  (object) array(
                    'value' => 'v1',
                    'label' => $this->h5pF->t('Version 1')
                  )
                )
              ),
              (object) array(
                'value' => 'PD',
                'label' => $this->h5pF->t('Public Domain'),
                'versions' => array(
                  (object) array(
                    'value' => '-',
                    'label' => '-'
                  ),
                  (object) array(
                    'value' => 'CC0 1.0',
                    'label' => $this->h5pF->t('CC0 1.0 Universal')
                  ),
                  (object) array(
                    'value' => 'CC PDM',
                    'label' => $this->h5pF->t('Public Domain Mark')
                  )
                )
              ),
              (object) array(
                'value' => 'C',
                'label' => $this->h5pF->t('Copyright')
              )
            )
          ),
          (object) array(
            'name' => 'version',
            'type' => 'select',
            'label' => $this->h5pF->t('License Version'),
            'options' => array()
          )
        )
      );
    }

    return $semantics;
  }
}