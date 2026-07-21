<?php

namespace Drupal\remote_file_key_provider\Plugin\KeyProvider;

use Drupal\key\Plugin\KeyProviderBase;
use Drupal\key\KeyInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;

/**
 * Defines an HTTP Key Provider.
 *
 * @KeyProvider(
 *   id = "remote_file_key_provider",
 *   label = @Translation("Remote File Key Provider"),
 *   description = @Translation("Retrieves the key dynamically from a secure HTTP endpoint."),
 *   tags = {
 *     "file",
 *     "remote",
 *   },
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
class RemoteFileKeyProvider extends KeyProviderBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ClientInterface $httpClient
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  public function getKeyValue(KeyInterface $key) {
    try {
      $response = $this->httpClient->request('GET', 'https://cornell.box.com/shared/static/2xynqh44x7080qv2q7bity8hx6dnkskn.txt');
      $data = $response->getBody()->getContents();
      return trim($data) ?? '';
    }
    catch (\Exception $e) {
      \Drupal::logger('remote_file_key_provider')->error('Failed fetching HTTP key: ' . $e->getMessage());
      return '';
    }
  }
}
