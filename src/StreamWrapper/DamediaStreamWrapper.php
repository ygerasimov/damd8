<?php

namespace Drupal\damedia\StreamWrapper;

// These classes are used to implement a stream wrapper class.
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Routing\UrlGeneratorTrait;

/**
 * Example stream wrapper class to handle session:// streams.
 *
 * This is just an example, as it could have horrible results if much
 * information were placed in the $_SESSION variable. However, it does
 * demonstrate both the read and write implementation of a stream wrapper.
 * You should *never* do this on any website accessable on the open
 * Internet.
 *
 * A "stream" is an important Unix concept for the reading and writing of
 * files and other devices. Reading or writing a "stream" just means that you
 * open some device, file, internet site, or whatever, and you don't have to
 * know at all what it is. All the functions that deal with it are the same.
 * You can read/write more from/to the stream, seek a position in the stream,
 * or anything else without the code that does it even knowing what kind
 * of device it is talking to. This Unix idea is extended into PHP's
 * mindset.
 *
 * The idea of "stream wrapper" is that this can be extended indefinitely.
 * The classic example is HTTP: With PHP you can do a
 * file_get_contents("http://drupal.org/projects") as if it were a file,
 * because the scheme "http" is supported natively in PHP. So Drupal adds
 * the public:// and private:// schemes, and contrib modules can add any
 * scheme they want to. This example adds the session:// scheme, which allows
 * reading and writing the $_SESSION['stream_wrapper_example'] key as if it
 * were a file.
 *
 * Drupal makes use of this concept to implement custom URI types like
 * "private://" and "public://".  To implement a stream wrapper, reading
 * the implementation of these stream wrappers is a very good way to get
 * started.
 *
 * To implement a stream wrapper in Drupal, you should do the following:
 *
 *  1. Create a class that implements the StreamWrapperInterface
 *     (Drupal\Core\StreamWrapper\StreamWrapperInterface).
 *
 *  2. Register the class with Drupal.  The best way to do this is to
 *     define a service in your MY_MODULE.services.yml file.  The
 *     service needs to be "tagged" with the scheme you want to implement,
 *     and, as so:
 *
 * @code
 *         tags:
 *           - { name: stream_wrapper, scheme: session }
 * @endcode
 *      See stream_wrapper_example.services.yml for an example.
 *
 *  3. (Optional) If you want to be able to access your files over the web,
 *     you need to add a route that handles, and implement hook_file_download().
 *     See stream_wrapper_example.routing.yml for an example of this, and
 *     file.module for the hook implementation.
 *
 * Note that because this implementation uses simple PHP arrays ($_SESSION)
 * it is limited to string values, so binary files will not work correctly.
 * Only text files can be used.  Also, experienced Drupal coders will
 * notice that we are violating one of Drupal's coding standards here:
 * normally, you should use "camelCase" for the names of your public
 * functions. We cannot do this here, since PHP itself defines the interface
 * used to interact with stream wrappers. Since PHP uses names_like_this
 * we are required to do the same here.
 *
 * @ingroup stream_wrapper_example
 */
class DamediaStreamWrapper implements StreamWrapperInterface {

  // We use this trait in order to get nice system-style links
  // for files stored via our stream wrapper.
//  use UrlGeneratorTrait;

  /**
   * A representation of an HTTP request.
   *
   * @var RequestStack
   */
  protected $requestStack;

  /**
   * Instance URI (stream).
   *
   * These streams will be references as 'session://example_target'
   *
   * @var String
   */
  protected $uri;

  /**
   * The content of the stream.
   *
   * Since this trivial example just uses the $_SESSION variable, this is
   * simply a reference to the contents of the related part of
   * $_SESSION['stream_wrapper_example'].
   */
  protected $sessionContent;

  /**
   * Pointer to where we are in a directory read.
   */
  protected $directoryPointer;

  /**
   * List of keys in a given directory.
   */
  protected $directoryKeys;

  /**
   * The pointer to the next read or write within the session variable.
   */
  protected $streamPointer;

  /**
   * The mode we are currently in.
   *
   * Possible values are FALSE, 'r', 'w'.
   */
  protected $streamMode;

  /**
   * Returns the type of stream wrapper.
   *
   * @return int
   *   See StreamWrapperInterface for permissible values.
   */
  public static function getType() {
    return StreamWrapperInterface::WRITE_VISIBLE;
  }

  /**
   * Constructor method.
   *
   * Note this cannot take any arguments; PHP's stream wrapper users
   * do not know how to supply them.
   */
  public function __construct() {
    // Dependency injection will not work here, since stream wrappers
    // are not loaded the normal way: PHP creates them automatically
    // when certain file functions are called.  This prevents us from
    // passing arguments to the constructor, which we'd need to do in
    // order to use standard dependency injection as is typically done
    // in Drupal 8.
    $this->requestStack = \Drupal::service('request_stack');
//    $helper = $this->getSessionWrapper();
//    $helper->setPath('.isadir.txt', TRUE);
    $this->streamMode = FALSE;
  }

  /**
   * Returns the name of the stream wrapper for use in the UI.
   *
   * @return string
   *   The stream wrapper name.
   */
  public function getName() {
    return t('DAMEdia wrapper');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return t('Use DAM images as they are on your system');
  }

  /**
   * Implements setUri().
   */
  public function setUri($uri) {
    $this->uri = $uri;
  }

  /**
   * Implements getUri().
   */
  public function getUri() {
    return $this->uri;
  }

  /**
   * Implements getTarget().
   *
   * The "target" is the portion of the URI to the right of the scheme.
   * So in session://example/test.txt, the target is 'example/test.txt'.
   *
   * @todo Figure out what this is in the new API.
   */
  public function getTarget($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    list($scheme, $target) = explode('://', $uri, 2);

    // Remove erroneous leading or trailing, forward-slashes and backslashes.
    // In the session:// scheme, there is never a leading slash on the target.
    return trim($target, '\/');
  }

  /**
   * Implements getDirectoryPath().
   *
   * In this case there is no directory string, so return an empty string.
   */
  public function getDirectoryPath() {
    return '';
  }

  /**
   * Overrides getExternalUrl().
   *
   * We have set up a helper function and menu entry to provide access to this
   * key via HTTP; normally it would be accessible some other way.
   */
  public function getExternalUrl() {
    $uri = $this->uri;
    // This is for full images.
    $uri = str_replace('damedia://', 'http://dam.docksal/sites/default/files/', $uri);
    // Replace for imagestyle presets.
    return str_replace('/damedia/', '/public/', $uri);
  }

  /**
   * Returns canonical, absolute path of the resource.
   *
   * Implementation placeholder. PHP's realpath() does not support stream
   * wrappers. We provide this as a default so that individual wrappers may
   * implement their own solutions.
   *
   * @return string
   *   Returns a string with absolute pathname on success (implemented
   *   by core wrappers), or FALSE on failure or if the registered
   *   wrapper does not provide an implementation.
   */
  public function realpath() {
    return $this->getLocalPath();
  }

  /**
   * Returns the local path.
   *
   * Here we aren't doing anything but stashing the "file" in a key in the
   * $_SESSION variable, so there's not much to do but to create a "path"
   * which is really just a key in the $_SESSION variable. So something
   * like 'session://one/two/three.txt' becomes
   * $_SESSION['stream_wrapper_example']['one']['two']['three.txt'] and the
   * actual path is "one/two/three.txt".
   *
   * @param string $uri
   *   Optional URI, supplied when doing a move or rename.
   */
  protected function getLocalPath($uri = NULL) {
    if (!isset($uri)) {
      $uri = $this->uri;
    }

    return $uri;
  }

  /**
   * Opens a stream, as for fopen(), file_get_contents(), file_put_contents().
   *
   * @param string $uri
   *   A string containing the URI to the file to open.
   * @param string $mode
   *   The file mode ("r", "wb" etc.).
   * @param int $options
   *   A bit mask of STREAM_USE_PATH and STREAM_REPORT_ERRORS.
   * @param string &$opened_path
   *   A string containing the path actually opened.
   *
   * @return bool
   *   Returns TRUE if file was opened successfully. (Always returns TRUE).
   *
   * @see http://php.net/manual/en/streamwrapper.stream-open.php
   */
  public function stream_open($uri, $mode, $options, &$opened_path) {
    $this->uri = $uri;

    $allowed_modes = array('r', 'rb');
    if (!in_array($mode, $allowed_modes)) {
      return FALSE;
    }

    // Attempt to fetch the URL's data using drupal_http_request().
    if (!$this->getStreamContent()) {
      return FALSE;
    }

    // Reset the stream pointer since this is an open.
    $this->stream_pointer = 0;
    return TRUE;
  }

  /**
   * Retrieve the underlying stream resource.
   *
   * This method is called in response to stream_select().
   *
   * @param int $cast_as
   *   Can be STREAM_CAST_FOR_SELECT when stream_select() is calling
   *   stream_cast() or STREAM_CAST_AS_STREAM when stream_cast() is called for
   *   other uses.
   *
   * @return resource|false
   *   The underlying stream resource or FALSE if stream_select() is not
   *   supported.
   *
   * @see stream_select()
   * @see http://php.net/manual/streamwrapper.stream-cast.php
   */
  public function stream_cast($cast_as) {
    return FALSE;
  }

  /**
   * Sets metadata on the stream.
   *
   * @param string $path
   *   A string containing the URI to the file to set metadata on.
   * @param int $option
   *   One of:
   *   - STREAM_META_TOUCH: The method was called in response to touch().
   *   - STREAM_META_OWNER_NAME: The method was called in response to chown()
   *     with string parameter.
   *   - STREAM_META_OWNER: The method was called in response to chown().
   *   - STREAM_META_GROUP_NAME: The method was called in response to chgrp().
   *   - STREAM_META_GROUP: The method was called in response to chgrp().
   *   - STREAM_META_ACCESS: The method was called in response to chmod().
   * @param mixed $value
   *   If option is:
   *   - STREAM_META_TOUCH: Array consisting of two arguments of the touch()
   *     function.
   *   - STREAM_META_OWNER_NAME or STREAM_META_GROUP_NAME: The name of the owner
   *     user/group as string.
   *   - STREAM_META_OWNER or STREAM_META_GROUP: The value of the owner
   *     user/group as integer.
   *   - STREAM_META_ACCESS: The argument of the chmod() as integer.
   *
   * @return bool
   *   Returns TRUE on success or FALSE on failure. If $option is not
   *   implemented, FALSE should be returned.
   *
   * @see http://www.php.net/manual/streamwrapper.stream-metadata.php
   */
  public function stream_metadata($path, $option, $value) {
    // We don't really do any of these, but we want to reassure the calling code
    // that there is no problem with chown or chgrp, even though we do not
    // actually support these.
    return TRUE;
  }

  /**
   * Change stream options.
   *
   * This method is called to set options on the stream.
   *
   * @param int $option
   *   One of:
   *   - STREAM_OPTION_BLOCKING: The method was called in response to
   *     stream_set_blocking().
   *   - STREAM_OPTION_READ_TIMEOUT: The method was called in response to
   *     stream_set_timeout().
   *   - STREAM_OPTION_WRITE_BUFFER: The method was called in response to
   *     stream_set_write_buffer().
   * @param int $arg1
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: The requested blocking mode:
   *     - 1 means blocking.
   *     - 0 means not blocking.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in seconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The buffer mode, STREAM_BUFFER_NONE or
   *     STREAM_BUFFER_FULL.
   * @param int $arg2
   *   If option is:
   *   - STREAM_OPTION_BLOCKING: This option is not set.
   *   - STREAM_OPTION_READ_TIMEOUT: The timeout in microseconds.
   *   - STREAM_OPTION_WRITE_BUFFER: The requested buffer size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise. If $option is not implemented, FALSE
   *   should be returned.
   */
  public function stream_set_option($option, $arg1, $arg2) {
    return FALSE;
  }

  /**
   * Truncate stream.
   *
   * Will respond to truncation; e.g., through ftruncate().
   *
   * @param int $new_size
   *   The new size.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise.
   *
   * @todo
   *   This one actually makes sense for the example.
   */
  public function stream_truncate($new_size) {
    return FALSE;
  }

  /**
   * Support for flock().
   *
   * The $_SESSION variable has no locking capability, so return TRUE.
   *
   * @param int $operation
   *   One of the following:
   *   - LOCK_SH to acquire a shared lock (reader).
   *   - LOCK_EX to acquire an exclusive lock (writer).
   *   - LOCK_UN to release a lock (shared or exclusive).
   *   - LOCK_NB if you don't want flock() to block while locking (not
   *     supported on Windows).
   *
   * @return bool
   *   Always returns TRUE at the present time. (no support)
   *
   * @see http://php.net/manual/en/streamwrapper.stream-lock.php
   */
  public function stream_lock($operation) {
    return TRUE;
  }

  /**
   * Support for fread(), file_get_contents() etc.
   *
   * @param int $count
   *   Maximum number of bytes to be read.
   *
   * @return string
   *   The string that was read, or FALSE in case of an error.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-read.php
   */
  public function stream_read($count) {
    if (is_string($this->streamContent)) {
      $remaining_chars = strlen($this->streamContent) - $this->streamPointer;
      $number_to_read = min($count, $remaining_chars);
      if ($remaining_chars > 0) {
        $buffer = substr($this->streamContent, $this->streamPointer, $number_to_read);
        $this->streamPointer += $number_to_read;
        return $buffer;
      }
    }
    return FALSE;
  }

  /**
   * Support for fwrite(), file_put_contents() etc.
   *
   * @param string $data
   *   The string to be written.
   *
   * @return int
   *   The number of bytes written (integer).
   *
   * @see http://php.net/manual/en/streamwrapper.stream-write.php
   */
  public function stream_write($data) {
    return FALSE;
  }

  /**
   * Support for feof().
   *
   * @return bool
   *   TRUE if end-of-file has been reached.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-eof.php
   */
  public function stream_eof() {
    return $this->streamPointer == strlen($this->streamContent);
  }

  /**
   * Support for fseek().
   *
   * @param int $offset
   *   The byte offset to got to.
   * @param int $whence
   *   SEEK_SET, SEEK_CUR, or SEEK_END.
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-seek.php
   */
  public function stream_seek($offset, $whence = SEEK_SET) {
    if (strlen($this->streamContent) >= $offset) {
      $this->streamPointer = $offset;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Support for fflush().
   *
   * @return bool
   *   TRUE if data was successfully stored (or there was no data to store).
   *   This always returns TRUE, as this example provides and needs no
   *   flush support.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-flush.php
   */
  public function stream_flush() {
    return TRUE;
  }

  /**
   * Support for ftell().
   *
   * @return int
   *   The current offset in bytes from the beginning of file.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-tell.php
   */
  public function stream_tell() {
    return $this->streamPointer;
  }

  /**
   * Support for fstat().
   *
   * @return array
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-stat.php
   */
  public function stream_stat() {
    $stat = array();
    $request = $this->drupal_http_request($this->uri, 'HEAD');
    if (empty($request->error)) {
      if (isset($request->headers['content-length'])) {
        $stat['size'] = $request->headers['content-length'];
      }
      elseif ($size = strlen($this->getStreamContent())) {
        // If the HEAD request does not return a Content-Length header, fall
        // back to performing a full request of the file to determine its file
        // size.
        $stat['size'] = $size;
      }
    }

    return !empty($stat) ? $this->getStat($stat) : FALSE;
  }

  /**
   * Support for fclose().
   *
   * @return bool
   *   TRUE if stream was successfully closed.
   *
   * @see http://php.net/manual/en/streamwrapper.stream-close.php
   */
  public function stream_close() {
    $this->streamPointer = 0;
    $this->streamContent = NULL;
    return TRUE;
  }

  /**
   * Support for unlink().
   *
   * @param string $uri
   *   A string containing the uri to the resource to delete.
   *
   * @return bool
   *   TRUE if resource was successfully deleted.
   *
   * @see http://php.net/manual/en/streamwrapper.unlink.php
   */
  public function unlink($uri) {
    // We return FALSE rather than TRUE so that managed file records can be
    // deleted.
    return TRUE;
  }

  /**
   * Support for rename().
   *
   * @param string $from_uri
   *   The uri to the file to rename.
   * @param string $to_uri
   *   The new uri for file.
   *
   * @return bool
   *   TRUE if file was successfully renamed.
   *
   * @see http://php.net/manual/en/streamwrapper.rename.php
   */
  public function rename($from_uri, $to_uri) {
    return FALSE;
  }

  /**
   * Gets the name of the directory from a given path.
   *
   * @param string $uri
   *   A URI.
   *
   * @return string
   *   A string containing the directory name.
   *
   * @see drupal_dirname()
   */
  public function dirname($uri = NULL) {
    list($scheme, $target) = explode('://', $uri, 2);
    $dirname = dirname($target);

    if ($dirname == '.') {
      $dirname = '';
    }

    return $scheme . '://' . $dirname;
  }

  /**
   * Support for mkdir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to create.
   * @param int $mode
   *   Permission flags - see mkdir().
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
   *
   * @return bool
   *   TRUE if directory was successfully created.
   *
   * @see http://php.net/manual/en/streamwrapper.mkdir.php
   */
  public function mkdir($uri, $mode, $options) {
    return FALSE;
  }

  /**
   * Support for rmdir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to delete.
   * @param int $options
   *   A bit mask of STREAM_REPORT_ERRORS.
   *
   * @return bool
   *   TRUE if directory was successfully removed.
   *
   * @see http://php.net/manual/en/streamwrapper.rmdir.php
   */
  public function rmdir($uri, $options) {
    return FALSE;
  }

  /**
   * Support for stat().
   *
   * This important function goes back to the Unix way of doing things.
   * In this example almost the entire stat array is irrelevant, but the
   * mode is very important. It tells PHP whether we have a file or a
   * directory and what the permissions are. All that is packed up in a
   * bitmask. This is not normal PHP fodder.
   *
   * @param string $uri
   *   A string containing the URI to get information about.
   * @param int $flags
   *   A bit mask of STREAM_URL_STAT_LINK and STREAM_URL_STAT_QUIET.
   *
   * @return array|bool
   *   An array with file status, or FALSE in case of an error - see fstat()
   *   for a description of this array.
   *
   * @see http://php.net/manual/en/streamwrapper.url-stat.php
   */
  public function url_stat($uri, $flags) {
    $this->uri = $uri;
    if ($flags & STREAM_URL_STAT_QUIET) {
      return @$this->stream_stat();
    }
    else {
      return $this->stream_stat();
    }
  }

  /**
   * Support for opendir().
   *
   * @param string $uri
   *   A string containing the URI to the directory to open.
   * @param int $options
   *   Whether or not to enforce safe_mode (0x04).
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-opendir.php
   */
  public function dir_opendir($uri, $options) {
    return FALSE;
  }

  /**
   * Support for readdir().
   *
   * @return string|bool
   *   The next filename, or FALSE if there are no more files in the directory.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-readdir.php
   */
  public function dir_readdir() {
    return FALSE;
  }

  /**
   * Support for rewinddir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-rewinddir.php
   */
  public function dir_rewinddir() {
    return FALSE;
  }

  /**
   * Support for closedir().
   *
   * @return bool
   *   TRUE on success.
   *
   * @see http://php.net/manual/en/streamwrapper.dir-closedir.php
   */
  public function dir_closedir() {
    return FALSE;
  }

  /**
   * Fetch the content of the file using drupal_http_request().
   */
  protected function getStreamContent() {
    if (!isset($this->stream_content)) {
      $this->stream_content = NULL;

      $request = $this->drupal_http_request($this->uri);
      if (empty($request->error) && !empty($request->data)) {
        $this->stream_content = $request->data;
      }
    }

    return $this->stream_content;
  }

  protected function drupal_http_request($url, $method = 'GET') {
    $method = strtolower($method);

    try {
      $response = \Drupal::httpClient()->{$method}($url);
      $data = (string) $response->getBody();
      if (empty($data)) {
        return FALSE;
      }
    }
    catch (RequestException $e) {
      return FALSE;
    }

    return $data;
  }

}
