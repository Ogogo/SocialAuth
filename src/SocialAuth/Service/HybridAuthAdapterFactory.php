<?php
/**
 * SocialAuth Module
 *
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */

namespace SocialAuth\Service;

use SocialAuth\Authentication\Adapter\HybridAuth as HybridAuthAdapter;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * @category   SocialAuth
 * @package    SocialAuth_Service
 */
class HybridAuthAdapterFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $services)
    {
        $moduleOptions = $services->get('SocialAuth-ModuleOptions');
        $zfcUserOptions = $services->get('zfcuser_module_options');

        $mapper = $services->get('SocialAuth-UserProviderMapper');
        $zfcUserMapper = $services->get('zfcuser_user_mapper');

        $adapter = new HybridAuthAdapter();
        $adapter->setOptions($moduleOptions);
        $adapter->setZfcUserOptions($zfcUserOptions);
        $adapter->setMapper($mapper);
        $adapter->setZfcUserMapper($zfcUserMapper);

        return $adapter;
    }
}
