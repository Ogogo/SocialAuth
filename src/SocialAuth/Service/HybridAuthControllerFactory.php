<?php
/**
 * SocialAuth Module
 *
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */

namespace SocialAuth\Service;

use SocialAuth\Controller\HybridAuthController;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */
class HybridAuthControllerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $controllerManager)
    {
        // Just making sure to instantiate and configure
        // It's not actually needed in HybridAuthController
        $hybridAuth = $controllerManager->getServiceLocator()->get('HybridAuth');

        $controller = new HybridAuthController();

        return $controller;
    }
}
