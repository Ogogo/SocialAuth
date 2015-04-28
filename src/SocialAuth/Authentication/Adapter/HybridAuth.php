<?php

namespace SocialAuth\Authentication\Adapter;

use Hybrid_Auth;
use SocialAuth\Mapper\UserProviderInterface;
use SocialAuth\Options\ModuleOptions;
use Zend\Authentication\Result;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\ServiceManager\ServiceManager;
use ZfcUser\Authentication\Adapter\AbstractAdapter;
use ZfcUser\Authentication\Adapter\AdapterChainEvent as AuthEvent;
use ZfcUser\Mapper\UserInterface as UserMapperInterface;
use ZfcUser\Options\UserServiceOptionsInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;

class HybridAuth extends AbstractAdapter implements ServiceManagerAwareInterface, EventManagerAwareInterface
{
    /**
     * @var Hybrid_Auth
     */
    protected $hybridAuth;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    /**
     * @var ModuleOptions
     */
    protected $options;

    /**
     * @var UserServiceOptionsInterface
     */
    protected $zfcUserOptions;

    /**
     * @var UserProviderInterface
     */
    protected $mapper;

    /**
     * @var UserMapperInterface
     */
    protected $zfcUserMapper;

    /**
     * @var EventManagerInterface
     */
    protected $events;

    public function authenticate(AuthEvent $authEvent)
    {        
        if ($this->isSatisfied()) { 
            $storage = $this->getStorage()->read();
            $authEvent->setIdentity($storage['identity'])
              ->setCode(Result::SUCCESS)
              ->setMessages(array('Authentication successful.'));

            return;
        }

        $enabledProviders = $this->getOptions()->getEnabledProviders();
        $provider = $authEvent->getRequest()->getMetadata('provider');

        if (empty($provider) || !in_array($provider, $enabledProviders)) {
            $authEvent->setCode(Result::FAILURE)
              ->setMessages(array('Invalid provider'));
            $this->setSatisfied(false);

            return false;
        }

        try {
            $hybridAuth = $this->getHybridAuth();
            $adapter = $hybridAuth->authenticate($provider);
            $userProfile = $adapter->getUserProfile();
            
        } catch (\Exception $ex) {
            $authEvent->setCode(Result::FAILURE)
              ->setMessages(array('Invalid provider'));
            $this->setSatisfied(false);

            return false;
        }

        if (!$userProfile) {
            $authEvent->setCode(Result::FAILURE_IDENTITY_NOT_FOUND)
              ->setMessages(array('A record with the supplied identity could not be found.'));
            $this->setSatisfied(false);

            return false;
        }

        $localUserProvider = $this->getMapper()->findUserByProviderId($userProfile->identifier, $provider);
        if (false == $localUserProvider) {
            if (!$this->getOptions()->getEnableSocialRegistration()) {
                $authEvent->setCode(Result::FAILURE_IDENTITY_NOT_FOUND)
                  ->setMessages(array('A record with the supplied identity could not be found.'));
                $this->setSatisfied(false);

                return false;
            }
            $method = $provider.'ToLocalUser';
            if (method_exists($this, $method)) {
                try {
                    $localUser = $this->$method($userProfile);
                } catch (Exception\RuntimeException $ex) {
                    $authEvent->setCode($ex->getCode())
                        ->setMessages(array($ex->getMessage()))
                        ->stopPropagation();
                    $this->setSatisfied(false);

                    return false;
                }
            } else {
                $localUser = $this->instantiateLocalUser();
                $localUser->setDisplayName($userProfile->displayName)
                          ->setPassword($provider);
                if (isset($userProfile->emailVerified) && !empty($userProfile->emailVerified)) {
                    $localUser->setEmail($userProfile->emailVerified);
                }
                $result = $this->insert($localUser, $provider, $userProfile);
            }
            $localUserProvider = clone($this->getMapper()->getEntityPrototype());
            $localUserProvider->setUserId($localUser->getId())
                ->setProviderId($userProfile->identifier)
                ->setProvider($provider);
            $this->getMapper()->insert($localUserProvider);

            // Trigger register.post event
            $this->getEventManager()->trigger('register.post', $this, array('user' => $localUser, 'userProvider' => $localUserProvider, 'userProfile' => $userProfile));
        }

        $zfcUserOptions = $this->getZfcUserOptions();

        if ($zfcUserOptions->getEnableUserState()) {
            // Don't allow user to login if state is not in allowed list
            $mapper = $this->getZfcUserMapper();
            $user = $mapper->findById($localUserProvider->getUserId());
            if (!in_array($user->getState(), $zfcUserOptions->getAllowedLoginStates())) {
                $authEvent->setCode(Result::FAILURE_UNCATEGORIZED)
                  ->setMessages(array('A record with the supplied identity is not active.'));
                $this->setSatisfied(false);

                return false;
            }
        }

        $authEvent->setIdentity($localUserProvider->getUserId());

        $this->setSatisfied(true);
        $storage = $this->getStorage()->read();
        $storage['identity'] = $authEvent->getIdentity();
        $this->getStorage()->write($storage);
        $authEvent->setCode(Result::SUCCESS)
          ->setMessages(array('Authentication successful.'));

          
    }

    /**
     * Get the Hybrid_Auth object
     *
     * @return Hybrid_Auth
     */
    public function getHybridAuth()
    {
        if (!$this->hybridAuth) {
            $this->hybridAuth = $this->getServiceManager()->get('HybridAuth');
        }

        return $this->hybridAuth;
    }

    /**
     * Set the Hybrid_Auth object
     *
     * @param  Hybrid_Auth    $hybridAuth
     * @return UserController
     */
    public function setHybridAuth(Hybrid_Auth $hybridAuth)
    {
        $this->hybridAuth = $hybridAuth;

        return $this;
    }

    /**
     * Retrieve service manager instance
     *
     * @return ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * Set service manager instance
     *
     * @param  ServiceManager $serviceManager
     * @return void
     */
    public function setServiceManager(ServiceManager $serviceManager)
    {
        $this->serviceManager = $serviceManager;
    }

    /**
     * set options
     *
     * @param  ModuleOptions $options
     * @return HybridAuth
     */
    public function setOptions(ModuleOptions $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * get options
     *
     * @return ModuleOptions
     */
    public function getOptions()
    {
        if (!$this->options instanceof ModuleOptions) {
            $this->setOptions($this->getServiceLocator()->get('SocialAuth-ModuleOptions'));
        }

        return $this->options;
    }

    /**
     * @param  UserServiceOptionsInterface $options
     * @return HybridAuth
     */
    public function setZfcUserOptions(UserServiceOptionsInterface $options)
    {
        $this->zfcUserOptions = $options;

        return $this;
    }

    /**
     * @return UserServiceOptionsInterface
     */
    public function getZfcUserOptions()
    {
        if (!$this->zfcUserOptions instanceof UserServiceOptionsInterface) {
            $this->setZfcUserOptions($this->getServiceManager()->get('zfcuser_module_options'));
        }

        return $this->zfcUserOptions;
    }

    /**
     * set mapper
     *
     * @param  UserProviderInterface $mapper
     * @return HybridAuth
     */
    public function setMapper(UserProviderInterface $mapper)
    {
        $this->mapper = $mapper;

        return $this;
    }

    /**
     * get mapper
     *
     * @return UserProviderInterface
     */
    public function getMapper()
    {
        if (!$this->mapper instanceof UserProviderInterface) {
            $this->setMapper($this->getServiceLocator()->get('SocialAuth-UserProviderMapper'));
        }

        return $this->mapper;
    }

    /**
     * set zfcUserMapper
     *
     * @param  UserMapperInterface $zfcUserMapper
     * @return HybridAuth
     */
    public function setZfcUserMapper(UserMapperInterface $zfcUserMapper)
    {
        $this->zfcUserMapper = $zfcUserMapper;

        return $this;
    }

    /**
     * get zfcUserMapper
     *
     * @return UserMapperInterface
     */
    public function getZfcUserMapper()
    {
        if (!$this->zfcUserMapper instanceof UserMapperInterface) {
            $this->setZfcUserMapper($this->getServiceLocator()->get('zfcuser_user_mapper'));
        }

        return $this->zfcUserMapper;
    }

    /**
     * Utility function to instantiate a fresh local user object
     *
     * @return mixed
     */
    protected function instantiateLocalUser()
    {
        $userModelClass = $this->getZfcUserOptions()->getUserEntityClass();

        return new $userModelClass;
    }

    // Provider specific methods

    protected function facebookToLocalUser($userProfile)
    {  
        if (!isset($userProfile->emailVerified) || empty($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Facebook before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);

        
        $result = $this->insert($localUser, 'facebook', $userProfile);

        $fb = json_encode($userProfile);
        
        $sm             = $this->getServiceManager();
        $entityManager  = $sm->get('Doctrine\ORM\EntityManager');

        $fbArray    = json_decode($fb,true);

        $userRole   = ($fbArray['userRole']) ? $fbArray['userRole'] : "candidate" ;
        
        if($userRole == "client"){ //CODE FOR ADDING AN ENTRY INTO EMPLOYERS TABLE, IF USER IS A CLIENT
            
            $employer    = new \Application\Entity\Employer;          
            
            $employer->setUser($localUser);                
            $employer->setFirstname($fbArray['firstName']);                
            $employer->setLastname($fbArray['lastName']);    
            $employer->setEmail($fbArray['email']);   
            
            //Set the user role "candidate" for users.
            $userRole       = $entityManager->getRepository('User\Entity\Role')->findOneBy(array('roleId' => $userRole));  
            //give the user the 'user' role
            $localUser->addRole($userRole); 

            $entityManager->persist($employer);
            $entityManager->flush();
        }       
        else { // IF USER IS A CANDIDATE
            //UPDATE FACEBOOK INFORMATIONS TO PROFILES TABLE
            $userProfile    = new \Application\Entity\Profile();           
            
            $userProfile->setFirstname($fbArray['firstName']);
            $userProfile->setLastname($fbArray['lastName']);
            $userProfile->setEmail($fbArray['email']);            
            $userProfile->setProfile($fbArray['aboutMe']);
            $userProfile->setLinkedinPhotourl($fbArray['photoURL']);

            $localUser->addProfile($userProfile);    

            //Set the user role "candidate" for users.
            $userRole       = $entityManager->getRepository('User\Entity\Role')->findOneBy(array('roleId' => 'candidate'));
            //give the user the 'user' role
            $localUser->addRole($userRole); 
           
            $positionCount = 0;   
            
            if(count($fbArray['work']) > 0){
                foreach($fbArray['work'] as $work){
                    $positionCount++;
                    
                    $profilePosition    = new \Application\Entity\Position();

                    if(isset($work['position']['name'])){
                        $profilePosition->setTitle($work['position']['name']);
                    }
                    
                    if(isset($work['description'])){
                        $profilePosition->setSummary($work['description']);
                    }
                    
                    if(isset($work['employer']['name'])){
                        $profilePosition->setCompanyname($work['employer']['name']);
                    }
                  
                    if(isset($work['start_date']) && isset($work['start_date'])){
                        $startDateArr = explode("-",$work['start_date']);
                        $startDate      = date("Y-m-d H:i:s", mktime(null, null, null, $startDateArr[1],$startDateArr[2],$startDateArr[0]));
                        $profilePosition->setStartdate(new \DateTime($startDate));
                    }
                  
                    if(isset($work['end_date']) && isset($work['end_date'])){
                        $endDateArr = explode("-", $work['end_date']);
                        $endDate      = date("Y-m-d H:i:s", mktime(null, null, null, $endDateArr[1],$endDateArr[2],$endDateArr[0]));
                        $profilePosition->setEnddate(new \DateTime($endDate));
                    }
                    else{
                        $profilePosition->setCurrent("1");
                    }
                    
                    $userProfile->addPosition($profilePosition);
                    $entityManager->persist($profilePosition);
                }
            }

            $educationCount = 0;
            if(count($fbArray['education']) > 0){
                foreach ($fbArray['education'] as $education) {
                    $educationCount++;
                    
                    $profileEducation    = new \Application\Entity\Education();           

                    if(isset($education['type'])){
                        $profileEducation->setCourse($education['type']);
                    }

                    if(isset($education['degree']['name'])){
                        $profileEducation->setSpecialization($education['degree']['name']);
                    }

                    if(isset($education['school']['name'])){
                        $profileEducation->setInstitute($education['school']['name']);
                    }

                    if(isset($education['year']['name'])){
                        $profileEducation->setYear($education['year']['name']);
                    }
                               
                    $userProfile->addEducation($profileEducation);
                    $entityManager->persist($profileEducation);
                }
            }

            $entityManager->persist($userProfile);
            $entityManager->flush();

            if($fbArray['photoURL']){
                //COPYING LINKEDIN PROFILE IMAGE TO PROJECT IMAGE DIRECTORY
                copy($fbArray['photoURL'],"public/img/profile_photos/".$userProfile->getId()."_linkedin.jpg");            
            }
        }
        unset($_SESSION['user_role']);
        return $localUser;
    }

    protected function foursquareToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified) || empty($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Foursquare before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'foursquare', $userProfile);

        return $localUser;
    }

    protected function googleToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified) || empty($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with Google before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }
        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }
        $localUser = $this->instantiateLocalUser();
        $localUser->setEmail($userProfile->emailVerified)
            ->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'google', $userProfile);

        return $localUser;
    }

    protected function linkedInToLocalUser($userProfile)
    {
        if (!isset($userProfile->emailVerified) || empty($userProfile->emailVerified)) {
            throw new Exception\RuntimeException(
                'Please verify your email with LinkedIn before attempting login',
                Result::FAILURE_CREDENTIAL_INVALID
            );
        }

        $sm             = $this->getServiceManager();
        $entityManager  = $sm->get('Doctrine\ORM\EntityManager');
        
        //Set the user role "candidate" for users.
        $userRole       = $entityManager->getRepository('User\Entity\Role')->findOneBy(array('roleId' => 'candidate'));

        $mapper = $this->getZfcUserMapper();
        if (false != ($localUser = $mapper->findByEmail($userProfile->emailVerified))) {
            return $localUser;
        }

        $localUser = $this->instantiateLocalUser();

        $li = json_encode($userProfile);
        
        $localUser->setDisplayName($userProfile->displayName)            
            ->setEmail($userProfile->emailVerified)
            ->setlinkedin($li)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'linkedIn', $userProfile);


        //UPDATE LINKEDIN INFORMATIONS TO PROFILES TABLE
        $userProfile    = new \Application\Entity\Profile();
       
        $liArray    = json_decode($li,true);
        
        $userProfile->setFirstname($liArray['firstName']);
        $userProfile->setLastname($liArray['lastName']);
        $userProfile->setEmail($liArray['email']);
        $userProfile->setPhone($liArray['phone']);
        $userProfile->setAddress1($liArray['address']);
        $userProfile->setCountry($liArray['country']);
        $userProfile->setCity($liArray['city']);
        $userProfile->setZip($liArray['zip']);
        $userProfile->setProfile($liArray['headline']);

        $userProfile->setLinkedinurl($liArray['profileURL']);

        $photoUrlJson    = json_decode($liArray['photoURL'],true);

        $userProfile->setLinkedinPhotourl($photoUrlJson['picture-url']);
        
        $skillsJson     = json_decode($liArray['skills'],true);
        $skillsArr      = $skillsJson['skill']; 

        $skillsStr ="";
        foreach($skillsArr as $skill)
        {
            if($skillsStr <> ""){
                $skillsStr .=", ";
            }
            $skillsStr .= $skill['skill']['name'];
        }
        $userProfile->setSkills($skillsStr);

        $localUser->addProfile($userProfile);    
       
        $positionsJson    = json_decode($liArray['positions'],true);
        
        $educationsJson    = json_decode($liArray['educations'],true);

        $recommendationsJson    = json_decode($liArray['recommendationsReceived'],true);
        
        $positionsCount         = $positionsJson['@attributes']['total'];
        $educationsCount        = $educationsJson['@attributes']['total'];
        $recommendationsCount   = $recommendationsJson['@attributes']['total']; 

        $positionCount = 0;

        if($positionsCount == 1){            
            $position = $positionsJson['position'];
            $positionCount++;
            
            $profilePosition    = new \Application\Entity\Position();

            if(isset($position['title'])){
                $profilePosition->setTitle($position['title']);
            }
            
            if(isset($position['summary'])){
                $profilePosition->setSummary($position['summary']);
            }
            
            if(isset($position['company']['name'])){
                $profilePosition->setCompanyname($position['company']['name']);
            }

            if(isset($position['company']['type'])){
                $profilePosition->setCompanytype($position['company']['type']);
            }
            
            if(isset($position['company']['industry'])){
                $profilePosition->setIndustry($position['company']['industry']);
            }

            if(isset($position['company']['size'])){
                $profilePosition->setCompanysize($position['company']['size']);
            }

            if(isset($position['is-current'])){
                $isCurrent  = ($position['is-current'] == "true") ? "1" : "0";

                $profilePosition->setCurrent($isCurrent);
            }
           
            if(isset($position['start-date']['month']) && isset($position['start-date']['year'])){
                $startDate      = date("Y-m-d H:i:s", mktime(null, null, null, $position['start-date']['month'],1,$position['start-date']['year']));
                $profilePosition->setStartdate(new \DateTime($startDate));
            }

            if($isCurrent == "0"){
                if(isset($position['end-date']['month']) && isset($position['end-date']['year'])){
                    $endDate        = date("Y-m-d H:i:s", mktime(null, null, null, $position['end-date']['month'],1,$position['end-date']['year']));
                    $profilePosition->setEnddate(new \DateTime($endDate));
                }
            }
            
            $userProfile->addPosition($profilePosition);
            $entityManager->persist($profilePosition);
        }
        else if($positionsCount > 1){
            
            foreach ($positionsJson['position'] as $position) {
                $positionCount++;
                
                $profilePosition    = new \Application\Entity\Position();

                if(isset($position['title'])){
                    $profilePosition->setTitle($position['title']);
                }
                
                if(isset($position['summary'])){
                    $profilePosition->setSummary($position['summary']);
                }
                
                if(isset($position['company']['name'])){
                    $profilePosition->setCompanyname($position['company']['name']);
                }

                if(isset($position['company']['type'])){
                    $profilePosition->setCompanytype($position['company']['type']);
                }
                
                if(isset($position['company']['industry'])){
                    $profilePosition->setIndustry($position['company']['industry']);
                }

                if(isset($position['company']['size'])){
                    $profilePosition->setCompanysize($position['company']['size']);
                }

                if(isset($position['is-current'])){
                    $isCurrent  = ($position['is-current'] == "true") ? "1" : "0";

                    $profilePosition->setCurrent($isCurrent);
                }
               
                if(isset($position['start-date']['month']) && isset($position['start-date']['year'])){
                    $startDate      = date("Y-m-d H:i:s", mktime(null, null, null, $position['start-date']['month'],1,$position['start-date']['year']));
                    $profilePosition->setStartdate(new \DateTime($startDate));
                }

                if($isCurrent == "0"){
                    if(isset($position['end-date']['month']) && isset($position['end-date']['year'])){
                        $endDate        = date("Y-m-d H:i:s", mktime(null, null, null, $position['end-date']['month'],1,$position['end-date']['year']));
                        $profilePosition->setEnddate(new \DateTime($endDate));
                    }
                }
                
                $userProfile->addPosition($profilePosition);
                $entityManager->persist($profilePosition);
            }  
        }   

        $educationCount = 0;
        if($educationsCount == 1){

            $education = $educationsJson['education'];
            $educationCount++;
           
            $profileEducation    = new \Application\Entity\Education();           

            if(isset($education['notes'])){
                $profileEducation->setCourse($education['notes']);
            }

            if(isset($education['field-of-study'])){
                $profileEducation->setSpecialization($education['field-of-study']);
            }

            if(isset($education['school-name'])){
                $profileEducation->setInstitute($education['school-name']);
            }

            if(isset($education['end-date']['year'])){
                $profileEducation->setYear($education['end-date']['year']);
            }
                       
            $userProfile->addEducation($profileEducation);
            $entityManager->persist($profileEducation);

        }
        else if($educationsCount > 1)
        {
            foreach ($educationsJson['education'] as $education) {
                $educationCount++;
                
                $profileEducation    = new \Application\Entity\Education();           

                if(isset($education['notes'])){
                    $profileEducation->setCourse($education['notes']);
                }

                if(isset($education['field-of-study'])){
                    $profileEducation->setSpecialization($education['field-of-study']);
                }

                if(isset($education['school-name'])){
                    $profileEducation->setInstitute($education['school-name']);
                }

                if(isset($education['end-date']['year'])){
                    $profileEducation->setYear($education['end-date']['year']);
                }
                           
                $userProfile->addEducation($profileEducation);
                $entityManager->persist($profileEducation);
            }
        }


        $recommendationCount = 0;
        if($recommendationsCount == 1){

            $recommendation = $recommendationsJson['recommendation'];
            $recommendationCount++;
            
            $profileRecommendation    = new \Application\Entity\Recommendation();           

            $recName = "";
            if(isset($recommendation['recommender']['first-name'])){
                $recName = $recommendation['recommender']['first-name'];                
            }
            if(isset($recommendation['recommender']['last-name'])){
                $recName .= " ".$recommendation['recommender']['last-name'];               
            }
            if(isset($recName)){
                $profileRecommendation->setRecommenderName($recName);
            }

            if(isset($recommendation['recommendation-text'])){
                $profileRecommendation->setRecommendation($recommendation['recommendation-text']);
            }
                       
            $userProfile->addRecommendation($profileRecommendation);
            $entityManager->persist($profileRecommendation);
            
        }
        else if($recommendationsCount > 1)
        {
            foreach ($recommendationsJson['recommendation'] as $recommendation) {
                $recommendationCount++;
                
                $profileRecommendation    = new \Application\Entity\Recommendation();           

                $recName = "";
                if(isset($recommendation['recommender']['first-name'])){
                    $recName = $recommendation['recommender']['first-name'];                
                }
                if(isset($recommendation['recommender']['last-name'])){
                    $recName .= " ".$recommendation['recommender']['last-name'];               
                }
                if(isset($recName)){
                    $profileRecommendation->setRecommenderName($recName);
                }

                if(isset($recommendation['recommendation-text'])){
                    $profileRecommendation->setRecommendation($recommendation['recommendation-text']);
                }
                           
                $userProfile->addRecommendation($profileRecommendation);
                $entityManager->persist($profileRecommendation);
            }  
        }
        
        $entityManager->persist($userProfile);

        if($photoUrlJson['picture-url']){
            //COPYING LINKEDIN PROFILE IMAGE TO PROJECT IMAGE DIRECTORY
            copy($photoUrlJson['picture-url'],"public/img/profile_photos/".$userProfile->getId()."_linkedin.jpg");            
        }
      
        //give the user the 'user' role
        $localUser->addRole($userRole);
        $entityManager->flush();
            
        //  $localUser->addRole(2);

        return $localUser;
    }

    protected function twitterToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setUsername($userProfile->displayName)
            ->setDisplayName($userProfile->firstName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'twitter', $userProfile);

        return $localUser;
    }

    protected function yahooToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'yahoo', $userProfile);

        return $localUser;
    }

    protected function tumblrToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
                  ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'tumblr', $userProfile);

        return $localUser;
    }

    protected function githubToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
                  ->setPassword(__FUNCTION__)
                  ->setEmail($userProfile->email);
        $result = $this->insert($localUser, 'github', $userProfile);

        return $localUser;
    }

    protected function mailruToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
                  ->setPassword(__FUNCTION__)
                  ->setEmail($userProfile->email);
        $result = $this->insert($localUser, 'mailru', $userProfile);

        return $localUser;
    }

    protected function odnoklassnikiToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__)
            ->setEmail($userProfile->email);
        $result = $this->insert($localUser, 'odnoklassniki', $userProfile);

        return $localUser;
    }

    protected function vkontakteToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__);
        $result = $this->insert($localUser, 'vkontakte', $userProfile);

        return $localUser;
    }

    protected function yandexToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__)
            ->setEmail($userProfile->email);
        $result = $this->insert($localUser, 'yandex', $userProfile);

        return $localUser;
    }

    protected function instagramToLocalUser($userProfile)
    {
        $localUser = $this->instantiateLocalUser();
        $localUser->setDisplayName($userProfile->displayName)
            ->setPassword(__FUNCTION__)
            ->setEmail($userProfile->email);
        $result = $this->insert($localUser, 'instagram', $userProfile);

        return $localUser;
    }

    /**
     * persists the user in the db, and trigger a pre and post events for it
     * @param  mixed  $user
     * @param  string $provider
     * @param  mixed  $userProfile
     * @return mixed
     */
    protected function insert($user, $provider, $userProfile)
    {
        $zfcUserOptions = $this->getZfcUserOptions();

        // If user state is enabled, set the default state value
        if ($zfcUserOptions->getEnableUserState()) {
            if ($zfcUserOptions->getDefaultUserState()) {
                $user->setState($zfcUserOptions->getDefaultUserState());
            }
        }

        $options = array(
            'user'          => $user,
            'provider'      => $provider,
            'userProfile'   => $userProfile,
        );

        $this->getEventManager()->trigger('registerViaProvider', $this, $options);
        $result = $this->getZfcUserMapper()->insert($user);
        $this->getEventManager()->trigger('registerViaProvider.post', $this, $options);

        return $result;
    }

    /**
     * Set Event Manager
     *
     * @param  EventManagerInterface $events
     * @return HybridAuth
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_called_class(),
        ));
        $this->events = $events;

        return $this;
    }

    /**
     * Get Event Manager
     *
     * Lazy-loads an EventManager instance if none registered.
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->events) {
            $this->setEventManager(new EventManager());
        }

        return $this->events;
    }
}
