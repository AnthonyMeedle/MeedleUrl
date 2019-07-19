<?php
namespace MeedleUrl\EventListeners;

use Thelia\Core\Event\GenerateRewrittenUrlEvent;
use Thelia\Core\Event\TheliaEvents;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Tools\URL;
use Thelia\Exception\UrlRewritingException;
use Thelia\Model\RewritingArgumentQuery;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Model\RewritingUrl;
use Thelia\Model\FolderQuery;
use Thelia\Model\ContentFolderQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Rewriting\RewritingResolver;

use Propel\Runtime\ActiveQuery\ModelCriteria;
use Thelia\Core\Event\UpdateSeoEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Form\Exception\FormValidationException;


class MeedleUrlListener implements EventSubscriberInterface
{
    /** @var Request */
    protected $request;

    public function __construct(Request $request){
        $this->request = $request;
    }
	
    public function updateSeoCat(UpdateSeoEvent $event, $eventName, EventDispatcherInterface $dispatcher){
        return $this->genericUpdateSeo(CategoryQuery::create(), $event, $dispatcher, 'category');
    }
    public function updateSeoFol(UpdateSeoEvent $event, $eventName, EventDispatcherInterface $dispatcher){
        return $this->genericUpdateSeo(FolderQuery::create(), $event, $dispatcher, 'folder');
    }
	
    protected function genericUpdateSeo(ModelCriteria $query, UpdateSeoEvent $event, EventDispatcherInterface $dispatcher = null, $viewName){
        if (null !== $object = $query->findPk($event->getObjectId())) {
			$event->setObject($object);
            try {
				$this->updateUnderObject($event, $viewName);
            } catch (UrlRewritingException $e) {
                throw new FormValidationException($e->getMessage(), $e->getCode());
            }
            $event->setObject($object);
        }
        return $object;
    }
	
	public function updateUnderObject(UpdateSeoEvent $event, $viewName){
		$parent = $event->getObject();
		$parent->setLocale($event->getLocale());
		
		switch($viewName){
			case 'category':
				if(null !== $result = CategoryQuery::create()->filterByParent($parent->getId())->find()){
					foreach($result as $object){
						$object->setLocale($event->getLocale());
						$this->updateRewrittenUrl('category', $object, $parent);
						$event->setObject($object);
						$this->updateUnderObject($event, 'category');
					}
				}
				if(null !== $result = ProductQuery::create()->filterByCategory($parent)->find()){
					foreach($result as $object){
						if($object->getDefaultCategoryId() == $parent->getId()){ 
							$object->setLocale($event->getLocale());
							$this->updateRewrittenUrl('product', $object, $parent);
						}
					}
				}
			break;
			case 'folder':
				if(null !== $result = FolderQuery::create()->filterByParent($parent->getId())->find()){
					foreach($result as $object){
						$object->setLocale($event->getLocale());
						$this->updateRewrittenUrl('folder', $object, $parent);
						$event->setObject($object);
						$this->updateUnderObject($event, 'folder');
					}
				}
				if(null !== $result = ContentQuery::create()->filterByFolder($parent)->find()){
					foreach($result as $object){
						if($object->getDefaultFolderId() == $parent->getId()){ 
							$object->setLocale($event->getLocale());
							$this->updateRewrittenUrl('content', $object, $parent);
						}
					}
				}
			break;
				
		}
	}
	
	public function updateRewrittenUrl($viewName, $object, $parent){
		$locale = $object->getLocale();
		$title = $object->getTitle();
		$urlParent = $parent->getRewrittenUrl($locale);
		
        if (null == $title) {
            throw new \RuntimeException('Impossible to create an url if title is null');
        }
        // Replace all weird characters with dashes
        $string = preg_replace('/[^\w\-~_\.]+/u', '-', $title);

        // Only allow one dash separator at a time (and make string lowercase)
        $cleanString = mb_strtolower(preg_replace('/--+/u', '-', $string), 'UTF-8');
		
		if($urlParent){
			$urlParent = rtrim($urlParent, '.html');
			$urlParent = str_replace('//', '/', $urlParent.'/');
			$urlParent = $this->ereg_caracspec( $urlParent );
		}
		$cleanString = $this->ereg_caracspec( $urlParent . $cleanString );
        $urlFilePart = rtrim($cleanString, '.-~_') . "/";
		$url=null;
        try {
            $i=0;
            while ($url = URL::getInstance()->resolve($urlFilePart)) {
				if($url->viewId == $object->getId() && $url->view == $viewName && $url->locale == $locale){
					break;
				}
                $i++;
                $urlFilePart = sprintf("%s-%d/", $cleanString, $i);
            }
        } catch (UrlRewritingException $e) {
            $rewritingUrl = new RewritingUrl();
            $rewritingUrl->setUrl($urlFilePart)
                ->setView($viewName)
                ->setViewId($object->getId())
                ->setViewLocale($locale)
                ->save()
            ;
			if(null !== $resultUrls = RewritingUrlQuery::create()->filterByView($viewName)->filterByViewId($object->getId())->filterByViewLocale($locale)->find()){
				foreach($resultUrls as $url){
					$url->setRedirected($rewritingUrl->getId())->save();
				}
			}
			$rewritingUrl->setRedirected(null)->save();
			$url=null;
        }
		if($url !== null){
			if(null !== $rewritingUrl = RewritingUrlQuery::create()->filterByUrl($url->rewrittenUrl)->findOne()){
				if(null !== $resultUrls = RewritingUrlQuery::create()->filterByView($viewName)->filterByViewId($object->getId())->filterByViewLocale($locale)->find()){
					foreach($resultUrls as $urlAutre){
						$urlAutre->setRedirected($rewritingUrl->getId())->save();
					}
				}
				$rewritingUrl->setRedirected(null)->save();
			}
		}
	}
	
	public function generateRewrittenUrl(GenerateRewrittenUrlEvent $event){
		$locale = $event->getLocale();
		$objet = $event->getObject();
		$title = $objet->getTitle();

        if (null == $title) {
            throw new \RuntimeException('Impossible to create an url if title is null');
        }
        // Replace all weird characters with dashes
        $string = preg_replace('/[^\w\-~_\.]+/u', '-', $title);

        // Only allow one dash separator at a time (and make string lowercase)
        $cleanString = mb_strtolower(preg_replace('/--+/u', '-', $string), 'UTF-8');
		$urlParent='';
		$idParent=0;
		switch($objet->getRewrittenUrlViewName()){
			case 'category' : 
				$idParent = $objet->getParent();
				if($idParent){
					$parent = CategoryQuery::create()->findPk($idParent);
					$parent->setLocale($locale);
					$urlParent = $parent->getRewrittenUrl($locale);
				}
			break;
			case 'product' : 
				$idParent=$this->request->get('category_id');
				if($idParent){
					$parent = CategoryQuery::create()->findPk($idParent);
					$parent->setLocale($locale);
					$urlParent = $parent->getRewrittenUrl($locale);
				}
			break;
			case 'folder' : 
				$idParent = $objet->getParent();
				if($idParent){
					$parent = FolderQuery::create()->findPk($idParent);
					$parent->setLocale($locale);
					$urlParent = $parent->getRewrittenUrl($locale);
				}
			break;
			case 'content' : 
				$idParent=$this->request->get('parent');
				if($idParent){
					$parent = FolderQuery::create()->findPk($idParent);
					$parent->setLocale($locale);
					$urlParent = $parent->getRewrittenUrl($locale);
				}
			break;
		}
		if($urlParent){
			$urlParent = rtrim($urlParent, '.html');
			$urlParent = str_replace('//', '/', $urlParent.'/');
			$urlParent = $this->ereg_caracspec( $urlParent );
		}
		$cleanString = $this->ereg_caracspec( $urlParent . $cleanString );
        $urlFilePart = rtrim($cleanString, '.-~_') . "/";
        try {
            $i=0;
            while (URL::getInstance()->resolve($urlFilePart)) {
                $i++;
                $urlFilePart = sprintf("%s-%d/", $cleanString, $i);
            }
        } catch (UrlRewritingException $e) {
            $rewritingUrl = new RewritingUrl();
            $rewritingUrl->setUrl($urlFilePart)
                ->setView($objet->getRewrittenUrlViewName())
                ->setViewId($objet->getId())
                ->setViewLocale($locale)
                ->save()
            ;
			$event->setUrl($urlFilePart);
        }
	}
	public function ereg_caracspec($chaine){

    $chaine = trim($chaine);

    if(function_exists('mb_strtolower'))
        $chaine = mb_strtolower($chaine, 'UTF-8');
    else
        $chaine = strtolower($chaine);

    $chaine = $this->supprAccent($chaine);

    $chaine = str_replace(
    	array(':', ';', ',', '°'),
        array('-', '-', '-', '-'),
        $chaine
     );

    $chaine = str_replace("(", "", $chaine);
    $chaine = str_replace(")", "", $chaine);
    $chaine = str_replace(" ", "-", $chaine);
    $chaine = str_replace("'", "-", $chaine);
    $chaine = str_replace("&nbsp;", "-", $chaine);
    $chaine = str_replace("\"", "-", $chaine);
    $chaine = str_replace("?", "", $chaine);
    $chaine = str_replace("*", "-", $chaine);
    $chaine = str_replace(".", "", $chaine);
    $chaine = str_replace("!", "", $chaine);
    $chaine = str_replace("+", "-", $chaine);
    $chaine = str_replace("ß", "ss", $chaine);
    $chaine = str_replace("%", "", $chaine);
    $chaine = str_replace("²", "2", $chaine);
    $chaine = str_replace("³", "3", $chaine);
    $chaine = str_replace("œ", "oe", $chaine);
	$chaine = str_replace(chr(128), "E", $chaine);
	$chaine = str_replace(chr(226), "E", $chaine);
	$chaine = str_replace(chr(146), "-", $chaine);
	$chaine = str_replace(chr(150), "-", $chaine);
	$chaine = str_replace(chr(151), "-", $chaine);
	$chaine = str_replace(chr(153), "", $chaine);
	$chaine = str_replace(chr(169), "", $chaine);
	$chaine = str_replace(chr(174), "", $chaine);
    $chaine = str_replace("&", "et", $chaine);

/*
    Brise les chaines de caractères multibytes

	$chaine = str_replace(chr(39), "-", $chaine);
	$chaine = str_replace(chr(234), "e", $chaine);
	$chaine = str_replace(chr(128), "E", $chaine);
	$chaine = str_replace(chr(226), "E", $chaine);
	$chaine = str_replace(chr(146), "-", $chaine);
	$chaine = str_replace(chr(150), "-", $chaine);
	$chaine = str_replace(chr(151), "-", $chaine);
	$chaine = str_replace(chr(153), "", $chaine);
	$chaine = str_replace(chr(169), "", $chaine);
	$chaine = str_replace(chr(174), "", $chaine);
*/

	return $chaine;
}

// suppression d'accent
	public function supprAccent($texte) {

   $texte = str_replace(    array(
                                'à', 'â', 'ä', 'á', 'ã', 'å',
                                'î', 'ï', 'ì', 'í',
                                'ô', 'ö', 'ò', 'ó', 'õ', 'ø',
                                'ù', 'û', 'ü', 'ú',
                                'é', 'è', 'ê', 'ë',
                                'ç', 'ÿ', 'ñ', 'ý'
                            ),
                            array(
                                'a', 'a', 'a', 'a', 'a', 'a',
                                'i', 'i', 'i', 'i',
                                'o', 'o', 'o', 'o', 'o', 'o',
                                'u', 'u', 'u', 'u',
                                'e', 'e', 'e', 'e',
                                'c', 'y', 'n', 'y'
                            ),
                            $texte
                );
    $texte = str_replace(    array(
                                'À', 'Â', 'Ä', 'Á', 'Ã', 'Å',
                                'Î', 'Ï', 'Ì', 'Í',
                                'Ô', 'Ö', 'Ò', 'Ó', 'Õ', 'Ø',
                                'Ù', 'Û', 'Ü', 'Ú',
                                'É', 'È', 'Ê', 'Ë',
                                'Ç', 'Ÿ', 'Ñ', 'Ý',
                            ),
                            array(
                                'A', 'A', 'A', 'A', 'A', 'A',
                                'I', 'I', 'I', 'I',
                                'O', 'O', 'O', 'O', 'O', 'O',
                                'U', 'U', 'U', 'U',
                                'E', 'E', 'E', 'E',
                                'C', 'Y', 'N', 'Y',
                            ),
                            $texte
                        );
	return $texte;
}
/*
    public function kernel404Resolver(FilterResponseEvent $event)
    {
        if ($event->getResponse()->getStatusCode() === 404) {

            // We first check if the RequestUri match and if not we check with the pathInfo
            $path = $this->request->getRequestUri();
            $pathInfo = $this->request->getPathInfo();

            $query = RedirectUrlQuery::create()->findOneByUrl($path);

            if (null !== $query && $path !== $query->getRedirect()) {
                $this->resolveRedirect($event, $query);
            } elseif ((null !== $query = RedirectUrlQuery::create()->findOneByUrl($pathInfo)) && $pathInfo !== $query->getRedirect()) {
                $this->resolveRedirect($event, $query);
            }
        }
    }
*/
    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::GENERATE_REWRITTENURL => ['generateRewrittenUrl', 128],
            TheliaEvents::CATEGORY_UPDATE_SEO   => ['updateSeoCat', 100],
            TheliaEvents::FOLDER_UPDATE_SEO   => ['updateSeoFol', 100]
        ];
    }
/*
    protected function resolveRedirect(FilterResponseEvent $event, RedirectUrl $query)
    {
        if (null !== $query->getTempRedirect() && '' !== $query->getTempRedirect()) {
            $event->setResponse(new RedirectResponse(URL::getInstance()->absoluteUrl($query->getTempRedirect()), 302));
        } else {
            $event->setResponse(new RedirectResponse(URL::getInstance()->absoluteUrl($query->getRedirect()), 301));
        }
    }*/
}