<?php
/**
 * SocialAuth Module
 *
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */

namespace SocialAuth\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */
class UserProviderViewHelperFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $serviceLocator = $serviceLocator->getServiceLocator();
        $viewHelper = new \SocialAuth\View\Helper\UserProvider();
        $viewHelper->setUserProviderMapper($serviceLocator->get('SocialAuth-UserProviderMapper'));

        return $viewHelper;
    }
}
