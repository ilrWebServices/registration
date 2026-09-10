<?php

namespace Drupal\ilr_registrations\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\salesforce\Rest\RestClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\salesforce_mapping\Entity\SalesforceMapping;
use Drupal\salesforce\SObject;
use Drupal\Core\Entity\EntityInterface;
use Drupal\salesforce\Rest\RestException;
use Drupal\salesforce\SelectQuery;
use Drupal\user\Entity\User;

/**
 * A Queue worker that processes the incoming Salesforce Commerce webhook data.
 *
 * @QueueWorker(
 *   id = "salesforce_commerce_webhook_processor",
 *   title = @Translation("Salesforce Commerce webhook processor"),
 *   cron = {"time" = 120}
 * )
 */
class SalesforceCommerceWebhookProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use LoggerChannelTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The salesforce rest client.
   *
   * @var \Drupal\salesforce\Rest\RestClientInterface
   */
  protected $sfapi;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new SalesforceCommerceWebhookProcessor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\salesforce\Rest\RestClientInterface $sfapi
   *   The salesforce rest client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, RestClientInterface $sfapi) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->sfapi = $sfapi;
    $this->logger = $this->getLogger('ilr_registrations_webhook');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('salesforce.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Load the order object from salesforce.
    try {
      $sf_order_object = $this->sfapi->objectRead('Order__c', $data['sf_order_id']);
    }
    catch (RestException $e) {
      $response = $e->getResponse();

      // If this was a connection error, throw a generic exception so that this
      // item will be retried. The exception will be logged.
      if ($response === NULL) {
        throw new \Exception($e->getMessage());
      }
      // If there was no connection error but there was some other error (e.g.,
      // the SF object was not found), just log it and return with no exception.
      // This will remove this queue item so that it will NOT be retried.
      else {
        $this->logger->error('Incoming salesforce webhook order lookup error for @sfid. Response code: @response_code. Response message: @response_message. Error message: @message', [
          '@sfid' => $data['sf_order_id'],
          '@response_code' => $response->getStatusCode(),
          '@response_message' => $response->getReasonPhrase(),
          '@message' => $e->getMessage(),
        ]);
        return;
      }
    }

    // @todo Maybe verify that $data['pos_order_id'] is the same as
    // $sf_order_object->field('Order_ID__c']?
    // Load the commerce order. Note that this Drupal entity id is stored in
    // Salesforce in the `Order_ID__c` field on Order_c objects.
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($sf_order_object->field('Order_ID__c'));

    // Map the commerce order to the salesforce order object.
    $order_mapping = $this->entityTypeManager->getStorage('salesforce_mapping')->load('order_to_reg_order');
    $this->createMapping($order_mapping, $sf_order_object, $order);

    // @todo Maybe verify $data['customer']['sf_customer_contact_id'] is
    // $sf_order_object->field('Purchaser__c').
    // Map the salesforce customer contact to the Drupal user for the order
    // billing profile user (not the billing profile entity itself).
    $sf_customer_contact_object = $this->sfapi->objectRead('Contact', $sf_order_object->field('Purchaser__c'));

    /** @var \Drupal\user\UserInterface $customer_user */
    $customer_user = $order->getCustomer();
    $contact_mapping = $this->entityTypeManager->getStorage('salesforce_mapping')->load('contact_user');

    // Don't map if the user is anonymous (because of guest checkout).
    if (!$customer_user->isAnonymous()) {
      $this->createMapping($contact_mapping, $sf_customer_contact_object, $customer_user);
    }
  }

  /**
   * Create a salesforce mapping for a given entity.
   *
   * @param \Drupal\salesforce_mapping\Entity\SalesforceMapping $mapping
   *   A salesforce mapping config entity.
   * @param \Drupal\salesforce\SObject $sf_object
   *   A salesforce object.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A Drupal entity. E.g., commerce_order, user, or participant.
   */
  protected function createMapping(SalesforceMapping $mapping, SObject $sf_object, EntityInterface $entity) {
    $mapped_object_storage = $this->entityTypeManager->getStorage('salesforce_mapped_object');
    $sf_mapped_object = $mapped_object_storage->loadBySfidAndMapping($sf_object->id(), $mapping);
    $drupal_mapped_object = $mapped_object_storage->loadByEntityAndMapping($entity, $mapping);

    if (empty($sf_mapped_object) && empty($drupal_mapped_object)) {
      // Create the mapping.
      $mapped_object = $mapped_object_storage->create([
        'drupal_entity' => [
          'target_type' => $mapping->getDrupalEntityType(),
        ],
        'salesforce_mapping' => $mapping->id(),
        'salesforce_id' => (string) $sf_object->id(),
      ]);

      $mapped_object
        ->setDrupalEntity($entity)
        ->setSalesforceRecord($sf_object);

      // This updates any mapped entity fields with values from salesforce
      // (e.g., the Financial Transaction ID field on orders) and saves the
      // mapped object.
      $mapped_object->pull();
    }
    else {
      // Log a notice for attempts at duplicates.
      $this->logger->notice('Duplicate Salesforce mapping `@mapping_id`. Salesforce `@sf_object_type` ID: @sfid. Drupal `@entity_type` ID: @entityid', [
        '@mapping_id' => $mapping->id(),
        '@sf_object_type' => $sf_object->type(),
        '@entity_type' => $entity->getEntityTypeId(),
        '@sfid' => $sf_object->id(),
        '@entityid' => $entity->id(),
      ]);
    }
  }

}
