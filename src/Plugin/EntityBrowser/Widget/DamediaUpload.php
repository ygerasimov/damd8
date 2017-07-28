<?php

namespace Drupal\damedia\Plugin\EntityBrowser\Widget;

use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lightning_media\Element\AjaxUpload;
use Drupal\lightning_media\MediaHelper;
use Drupal\media_entity\MediaInterface;
use Drupal\lightning_media\Plugin\EntityBrowser\Widget\FileUpload;

/**
 * An Entity Browser widget for creating media entities from uploaded files.
 *
 * @EntityBrowserWidget(
 *   id = "damedia_upload",
 *   label = @Translation("DAM Upload"),
 *   description = @Translation("Upload files to DAM."),
 * )
 */
class DamediaUpload extends FileUpload {

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\media_entity\MediaInterface $entity */
    $entity = $element['entity']['#entity'];

    $file = MediaHelper::useFile(
      $entity,
      MediaHelper::getSourceField($entity)->entity
    );
    $file->setPermanent();

    $file->save();

    // Upload actual file to DAM and replace URI.
    $this->damediaUploadFileToDam($file);

    $entity->save();

    $selection = [
      $this->configuration['return_file'] ? $file : $entity,
    ];
    $this->selectEntities($selection, $form_state);
  }

  /**
   * Upload file to DAM.
   */
  protected function damediaUploadFileToDam($file) {

    $fileObject = (object) array(
      'filename' => $file->getFilename(),
    );

    $request_body = (object) array(
      'entity' => $fileObject,
      'content' => base64_encode(file_get_contents($file->getFileUri()))
    );

    $data_string = json_encode($request_body);

    $dam_base_url = 'http://dam.docksal';
    $url = $dam_base_url . '/api/file';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_string))
    );

    $result = json_decode(curl_exec($ch));

    // Check HTTP status code
    if (!curl_errno($ch)) {
      $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($http_code != 200) {
        \Drupal::logger('damedia')->error('Unexpected HTTP code %code, message "%message"', array('%code' => $http_code, '%message' => $result));
        curl_close($ch);
        return;
      }

      \Drupal::logger('damedia')->notice('%name was uploaded to DAM. Result: %result',
        array(
          '%name' => $file->getFilename(),
          '%result' => $result,
        ));
      curl_close($ch);
    }

    // We assume that on DAM files got uploaded to sites/default/files folder.
    $uploaded_filename = trim($result, '/');

    // Dirty query to database. If you simply set $file->setUri() it throws curl error that needs some debugging.
    db_query('UPDATE {file_managed} SET uri = :uri WHERE fid = :fid', array(':uri' => 'damedia://' . $uploaded_filename, ':fid' => $file->id()));
  }

}
