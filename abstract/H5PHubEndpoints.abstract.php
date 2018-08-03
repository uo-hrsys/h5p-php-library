<?php
abstract class H5PHubEndpoints {
  const CONTENT_TYPES = 'api.h5p.org/v1/content-types/';
  const SITES = 'api.h5p.org/v1/sites';

  public static function createURL($endpoint) {
    $protocol = (extension_loaded('openssl') ? 'https' : 'http');
    return "{$protocol}://{$endpoint}";
  }
}