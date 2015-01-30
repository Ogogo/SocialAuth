<?php
/**
 * SocialAuth Module
 *
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */

namespace SocialAuth\Service;

use SocialAuth\Controller\UserController;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */
class UserControllerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $controllerManager)
    {
        $mapper = $controllerManager->getServiceLocator()->get('SocialAuth-UserProviderMapper');
        $hybridAuth = $controllerManager->getServiceLocator()->get('HybridAuth');
        $moduleOptions = $controllerManager->getServiceLocator()->get('SocialAuth-ModuleOptions');

        $controller = new UserController();
        $controller->setMapper($mapper);
        $controller->setHybridAuth($hybridAuth);
        $controller->setOptions($moduleOptions);

        return $controller;
    }
}
