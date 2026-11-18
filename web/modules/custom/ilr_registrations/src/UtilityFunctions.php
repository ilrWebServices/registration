<?php

namespace Drupal\ilr_registrations;

class UtilityFunctions {

  static public function getAllowedUsStates() {
    /** @var \CommerceGuys\Addressing\Subdivision\SubdivisionRepositoryInterface $subdivision_repository */
    $subdivision_repository = \Drupal::service('address.subdivision_repository');

    // Return the list of states for the US.
    return $subdivision_repository->getList(['US']);
  }

}
