<?php
/**
 * LiteMage
 * @package   LiteSpeed_LiteMage
 * @copyright  Copyright (c) LiteSpeed Technologies, Inc. All rights reserved. (https://www.litespeedtech.com)
 * @license     https://opensource.org/licenses/GPL-3.0
 */

namespace Litespeed\Litemage\Model;

use Magento\Framework\View\Layout\Element as Element;

/**
 * Class CacheControl
 *
 */
class CacheControl
{

    /**
     * @var \Litespeed\Litemage\Model\Config
     */
    protected $config;

    /*
     * Cache related headers only for LiteSpeed Web Server
     */

    const LSHEADER_PURGE = 'X-LiteSpeed-Purge';
    const LSHEADER_CACHE_CONTROL = 'X-LiteSpeed-Cache-Control';
    const LSHEADER_CACHE_TAG = 'X-LiteSpeed-Tag';
    const LSHEADER_CACHE_VARY = 'X-LiteSpeed-Vary';
    const ENV_VARYCOOKIE_DEFAULT = '_lscache_vary'; // hardcoded by LSWS

    const LSHEADER_DEBUG_INFO = 'X-LiteMage-Debug-Info';
    const LSHEADER_DEBUG_CC = 'X-LiteMage-Debug-CC';
    const LSHEADER_DEBUG_VARY = 'X-LiteMage-Debug-Vary';
    const LSHEADER_DEBUG_Tag = 'X-LiteMage-Debug-Tag';
    const LSHEADER_DEBUG_Purge = 'X-LiteMage-Debug-Purge';

    protected $_debug = false;
    protected $_bypassedContext = [];
    protected $_purgeTags = [];
    protected $_cacheTags = [];
    protected $_isCacheable = -1; // -1: not set, 0: No, 1: true
    protected $_isEsiRequest = false;
    protected $_moduleEnabled;
    protected $_hasESI = false;
    protected $_ttl = 0;
    protected $_nocacheReason = '';

    /** @var \Magento\Framework\App\Http\Context */
    protected $httpContext;
    protected $request;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected $cookieMetadataFactory;
    protected $helper;

    /**
     * constructor
     *
     * @param \Magento\Framework\App\Http\Context $httpContext,
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
     * @param \Magento\Framework\App\Request\Http $request,
     * @param \Litespeed\Litemage\Model\Config $config,
     * @param \Litespeed\Litemage\Helper\Data $helper
     */
    public function __construct(\Magento\Framework\App\Http\Context $httpContext,
            \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
            \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
            \Magento\Framework\App\Request\Http $request,
            \Litespeed\Litemage\Model\Config $config,
            \Litespeed\Litemage\Helper\Data $helper
    )
    {
        $this->httpContext = $httpContext;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->request = $request;
        $this->config = $config;
        $this->helper = $helper;
        $this->_moduleEnabled = $config->moduleEnabled();
        if ($this->_moduleEnabled) {
            $this->_debug = $this->config->debugEnabled();
            $this->_bypassedContext = $this->config->getBypassedContext();
        }

        $reason = 'CacheControl constructor ';
        if (!$this->_moduleEnabled) {
            $this->setNotCacheable("$reason module disabled");
        } elseif (!$request->isGet() && !$request->isHead()) {
            $this->setNotCacheable($reason . $request->getMethod());
        } elseif ($request->isAjax() && $request->getQuery('_')) {
            $this->setNotCacheable("$reason ajax with random string");
        }
    }

    public function moduleEnabled()
    {
        return $this->_moduleEnabled;
    }

    public function debugLog($message)
    {
        if ($this->_debug) {
            $this->helper->debugLog($message);
        }
    }

    public function debugEnabled()
    {
        return $this->_debug;
    }

	public function needPurge()
	{
		return ($this->_moduleEnabled && !empty($this->_purgeTags));
	}

	/**
	 * Add purgeable tags
	 * @param array $tags
	 * @param string $source
	 *
	 */
    public function addPurgeTags($tags, $source)
    {
		if (!empty($tags)) {
			$this->_purgeTags = array_unique(array_merge($this->_purgeTags, $tags));
		}
		if ($this->_debug) {
			$this->debugLog('add purge tags from '
					. $source . ' : ' . implode(',', $tags)
					. ' Result=' . implode(',',$this->_purgeTags) );
		}
    }

    public function setCacheable($ttl, $msg)
    {
        // cannot set from non cacheable to cacheable
        if ($this->_isCacheable == -1) {
            $this->_isCacheable = 1;
            $this->debugLog('setCacheable from ' . $msg);
        }
        if ($ttl > 0) {
            $this->_ttl = $ttl;
        }
    }

    public function setNotCacheable($reason, $shortReason='')
    {
        if ($this->_isCacheable != 0) {
            $this->_isCacheable = 0;
            $this->debugLog('setNotCacheable ' . $reason);
            $this->_nocacheReason = $shortReason ?: $reason;
        }
    }

    public function setEsiRequest($isEsiReq = true)
    {
        $this->_isEsiRequest = $isEsiReq;
    }

    public function isCacheable()
    {
        return ($this->_isCacheable === 1);
    }

    // either unknown or cacheable
    public function maybeCacheable()
    {
        return ($this->_isCacheable !== 0);
    }

    public function canInjectEsi()
    {
        return ($this->_isCacheable === 1 && !$this->_isEsiRequest);
    }

    public function getEsiUrl($handles, $blockName)
    {
        $url = $this->helper->getUrl(
                'litemage/block/esi',
                [
            'b' => $blockName,
            'h' => $this->encodeEsiHandles($handles)
                ]
        );

        return $url;
    }

    protected function encodeEsiHandles($handles)
    {
        $translator = $this->config->getEsiHandlesTranslator();
        $ignored = $this->config->getEsiHandlesIgnored();
        $used = [];

        foreach ($handles as $h) {
            if (isset($translator[$h])) {
                $used[] = $translator[$h];
            } else {
                foreach ($ignored as $i) {
                    if (strpos($h, $i) !== false) {
                        break 2;
                    }
                }
                $used[] = $h;
            }
        }
        return implode(',', $used);
    }

    public function decodeEsiHandles($reqParam)
    {
        $translator = $this->config->getEsiHandlesTranslator();
        $used = explode(',', $reqParam);
        $handles = [];
        foreach ($used as $h) {
            if ($realHandle = array_search($h, $translator)) {
                $handles[] = $realHandle;
            } else {
                $handles[] = $h;
            }
        }
        return $handles;
    }

    public function setEsiOn($isOn)
    {
        $this->_hasESI = $isOn;
    }

    public function setCacheControlHeaders($response)
    {
        $cacheControlHeader = '';
        $lstags = '';
        $rawvarydata = '';
        $changed = $this->_checkCacheVary($rawvarydata);

        $responsecode = $response->getHttpResponseCode();
        if (( $responsecode == 200 || $responsecode == 404) && ($this->_isCacheable == 1)
        ) {
            // cacheable
            $lstags = $this->_setCacheTagHeader($response);

            $cacheControlHeader = 'public,max-age=' . $this->_ttl;
        }
        if ($this->_hasESI) {
            if ($cacheControlHeader != '')
                $cacheControlHeader .= ',';
            $cacheControlHeader .= 'esi=on';
        }
        if ($cacheControlHeader) {
            $response->setHeader(self::LSHEADER_CACHE_CONTROL, $cacheControlHeader);
            $this->debugLog('SetCacheControlHeaders:' . $cacheControlHeader . ' Tags:' . $lstags);

        }
        if ($cch = $response->getHeader('Cache-Control')) {
            if (preg_match('/public.*s-maxage=(\d+)/', $cch->getFieldValue(),
                            $matches)) {
                $maxAge = $matches[1];
                $response->setNoCacheHeaders();
            }
        }
        if ($this->_debug == 2) {
            // show debug headers
            $response->setHeader(self::LSHEADER_DEBUG_CC, $cacheControlHeader);
            $response->setHeader(self::LSHEADER_DEBUG_Tag, $lstags);
            $response->setHeader(self::LSHEADER_DEBUG_INFO, substr(htmlspecialchars(str_replace("\n", ' ', $this->_nocacheReason)), 0, 256));
            $response->setHeader(self::LSHEADER_DEBUG_VARY, $rawvarydata);
        }
    }

    protected function _setCacheTagHeader($response)
    {
        $lstags = '';
        if (!empty($this->_cacheTags)) {
            $lstags = $this->translateTags($this->_cacheTags);
            if (is_array($lstags)) {
                $lstags = implode(',', array_unique($lstags));
            }
            $response->setHeader(self::LSHEADER_CACHE_TAG, $lstags);
            $response->clearHeader('X-Magento-Tags');
        }
        return $lstags;
    }

    protected function _checkCacheVary(&$rawdata)
    {
        $varyString = null;
        $data = $this->httpContext->getData();
       if (!empty($data) && !empty($this->_bypassedContext)) {
            // already not cacheable, like POST request, do filter
            $data = array_filter($data, function($k) {
                return (!in_array($k, $this->_bypassedContext));
            }, ARRAY_FILTER_USE_KEY);
        }

        if (!empty($data)) {
            ksort($data);
            $rawdata = http_build_query($data);
            $varyString = sha1(serialize($data));
        }

        $changed = false;
        $currentVary = $this->request->get(self::ENV_VARYCOOKIE_DEFAULT);
        if ($varyString != $currentVary) {
            if ($varyString) {
                $sensitiveCookMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->setSensitiveCookie(self::ENV_VARYCOOKIE_DEFAULT,
                        $varyString, $sensitiveCookMetadata);
            } else {
                $cookieMetadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()->setPath('/');
                $this->cookieManager->deleteCookie(self::ENV_VARYCOOKIE_DEFAULT,
                        $cookieMetadata);
            }
            $changed = true;
            $rawdata .=  ' changed';
            $this->setNotCacheable("EnvVary $rawdata");
        }

        if ($this->_debug && $rawdata) {
            $this->debugLog("EnvVary: $rawdata");
        }
        return $changed;
    }

    public function setPurgeHeaders($response)
    {
        if (empty($this->_purgeTags))
			return;

		if (in_array('*', $this->_purgeTags)) {
			$purgeTags = '*';
		} else {
			$purgeTags = 'tag=' . implode(',tag=', array_unique($this->translateTags($this->_purgeTags)));
		}
		$response->setHeader(self::LSHEADER_PURGE, $purgeTags);
        if ($this->_debug) {
            $this->debugLog('Set purge header ' . $purgeTags);
            if ($this->_debug == 2) {
                $response->setHeader(self::LSHEADER_DEBUG_Purge, $purgeTags);
            }
        }
    }

    public function addCacheTags($tags)
    {
        if (is_array($tags)) {
            $this->_cacheTags = array_unique(array_merge($this->_cacheTags,
                            $tags));
        } else if ($tags && !in_array($tags, $this->_cacheTags)) {
            $this->_cacheTags[] = $tags;
        }
    }

    public function setCacheTags($tags)
    {
        $this->_cacheTags = $tags;
    }

	/**
	 * translateTags
	 * @param string or array of strings $tagString
	 * @return string or array
	 */
    // input can be array or string
	public function translateTags($tagString)
    {
		$search = ['_block',
			'catalog_product_',
			'catalog_product',
			'catalog_category_product_', // sequence matters, need to be in front of shorter ones
			'catalog_category_' ];
		$replace = ['.B', 'P.', 'P', 'C.', 'C.'];

        $lstags = str_replace($search, $replace, $tagString);

		//$this->debugLog("in translate tags from = $tagString , to = $lstags");
        return $lstags;
    }

	// used by ESI blocks
    public function getElementCacheTags($layout, $elementName)
    {
        if (!$layout->hasElement($elementName))
            return '';

        $tags = [];

        // get all children blocks
        $blocks = [];
        $allnames = [$elementName];
        $etypes = [Element::TYPE_BLOCK, Element::TYPE_CONTAINER, Element::TYPE_UI_COMPONENT];

        while (count($allnames)) {
            $parent = array_pop($allnames);
            if ($block = $layout->getBlock($parent)) {
                $block->setData('litemage_esi', 1);
                if ($block instanceof \Magento\Framework\DataObject\IdentityInterface) {
                    // special handling for known blocks
                    if ($block instanceof \Magento\Theme\Block\Html\Topmenu) {
                        $tags[] = 'topnav';
                    } else {
                        $tags = array_merge($tags, $block->getIdentities());
                    }
                    $block->setData('litemage_esi', 2);
                }
            }
            $childNames = $layout->getChildNames($parent);
            foreach ($childNames as $childName) {
                $type = $layout->getElementProperty($childName, 'type');
                if (in_array($type, $etypes)) {
                    array_push($allnames, $childName);
                }
            }
        }
		if (!empty($tags)) {
			$lstag = implode(',', array_unique($this->translateTags($tags)));
		}
		else {
            $lstag = $elementName;
		}
        return $lstag;
    }

}
