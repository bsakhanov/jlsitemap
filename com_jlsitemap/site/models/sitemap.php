<?php
/**
 * @package    JLSitemap Component
 * @version    @version@
 * @author     Joomline - joomline.ru
 * @copyright  Copyright (c) 2010 - 2018 Joomline. All rights reserved.
 * @license    GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link       https://joomline.ru/
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

class JLSitemapModelSitemap extends BaseDatabaseModel
{
	/**
	 * Sitemap xml
	 *
	 * @var string
	 *
	 * @since 1.1.0
	 */
	protected $_xml = null;

	/**
	 * Object with urls array
	 *
	 * @var object
	 *
	 * @since 1.1.0
	 */
	protected $_urls = null;

	/**
	 * Menu items array
	 *
	 * @var array
	 *
	 * @since 1.1.0
	 */
	protected $_menuItems = null;

	/**
	 * Method to generate sitemap.xml
	 *
	 * @param bool $debug Debug generation
	 *
	 * @return bool|object Array if successful, false otherwise and internal error is set.
	 *
	 * @since 1.1.0
	 */
	public function generate($debug = false)
	{
		// Get urs
		$urls = $this->getUrls();

		// Generate sitemap file
		if (!$debug)
		{
			// Get sitemap xml
			$xml = $this->getXML($urls->includes);

			// Regexp filter
			$filterRegexp = ComponentHelper::getParams('com_jlsitemap')->get('filter_regexp');
			if (!empty($filterRegexp))
			{
				foreach (ArrayHelper::fromObject($filterRegexp) as $regexp)
				{
					if (!empty($regexp['pattern']))
					{
						$xml = preg_replace($regexp['pattern'], $regexp['replacement'], $xml);
					}
				}
			}

			// Create sitemap file
			$file = JPATH_ROOT . '/sitemap.xml';
			if (File::exists($file))
			{
				File::delete($file);
			}
			File::append($file, $xml);

			// Save sitemap date
			$component          = new stdClass();
			$component->element = 'com_jlsitemap';
			$component->params  = ComponentHelper::getParams('com_jlsitemap');
			$component->params->set('sitemap_date', stat($file)['mtime']);
			$component->params = $component->params->toString();
			$this->getDbo()->updateObject('#__extensions', $component, array('element'));
		}

		return $urls;
	}

	/**
	 * Method to get sitemap xml sting
	 *
	 * @param array $rows Include urls array
	 *
	 * @return string
	 *
	 * @since 1.1.0
	 */
	protected function getXML($rows = array())
	{
		if ($this->_xml === null)
		{
			$rows = (empty($rows)) ? $this->getUrls()->includes : $rows;

			// Create sitemap
			$sitemap = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>'
				. '<urlset'
				. ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
				. ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
				. ' xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.w3.org/1999/xhtml http://www.w3.org/2002/08/xhtml/xhtml1-strict.xsd"'
				. ' xmlns:xhtml="http://www.w3.org/1999/xhtml"'
				. ' xhtml="http://www.w3.org/1999/xhtml"'
				. '/>');

			// Add urls
			foreach ($rows as $row)
			{
				if ($loc = $row->get('loc', false))
				{
					$url = $sitemap->addChild('url');

					// Loc
					$url->addChild('loc', $loc);

					// Changefreq
					if ($changefreq = $row->get('changefreq', false))
					{
						$url->addChild('changefreq', $changefreq);
					}

					// Priority
					if ($priority = $row->get('priority', false))
					{
						$url->addChild('priority', $row->get('priority'));
					}

					// Lastmod
					if ($lastmod = $row->get('lastmod', false))
					{
						$url->addChild('lastmod', Factory::getDate($lastmod)->toISO8601());
					}

					// Alternates
					if ($alternates = $row->get('alternates', false))
					{
						// Add x-default
						if (!isset($alternates['x-default']) && isset($alternates[Factory::getLanguage()->getDefault()]))
						{
							$alternates['x-default'] = $alternates[Factory::getLanguage()->getDefault()];
						}

						foreach ($alternates as $lang => $href)
						{
							$alternate = $url->addChild('xhtml:link', '', 'xhtml');
							$alternate->addAttribute('rel', 'alternate');
							$alternate->addAttribute('hreflang', $lang);
							$alternate->addAttribute('href', $href);
						}
					}
				}
			}

			$this->_xml = $sitemap->asXML();
		}

		return $this->_xml;
	}

	/**
	 * Method to get sitemap urls array
	 *
	 * @return object
	 *
	 * @since 1.1.0
	 */
	protected function getUrls()
	{
		if ($this->_urls === null)
		{
			$config           = ComponentHelper::getParams('com_jlsitemap');
			$siteConfig       = Factory::getConfig();
			$siteSef          = ($siteConfig->get('sef') == 1);
			$siteRobots       = $siteConfig->get('robots');
			$guestAccess      = array_unique(Factory::getUser(0)->getAuthorisedViewLevels());
			$multilanguage    = Multilanguage::isEnabled();
			$defaultLanguage  = ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
			$changefreqValues = array('always'  => 1, 'hourly' => 2, 'daily' => 3, 'weekly' => 4,
			                          'monthly' => 5, 'yearly' => 6, 'never' => 7);

			// Prepare menus filter
			$filterMenus = ($config->get('filter_menu')) ? $config->get('filter_menu_menus', array()) : false;

			// Prepare menu items filter;
			$filterMenuItems = (is_array($filterMenus)) ? array() : false;

			// Prepare menu home filter
			$filterMenuHomes = array();

			// Prepare raw filter
			$filterRaw = ($config->get('filter_raw_index') || $config->get('filter_raw_component')
				|| $config->get('filter_raw_get')) ? array() : false;
			if ($config->get('filter_raw_index'))
			{
				$filterRaw[] = 'index.php';
			}
			if ($config->get('filter_raw_component'))
			{
				$filterRaw[] = 'component/';
			}
			if ($config->get('filter_raw_get'))
			{
				$filterRaw[] = '?';
			}

			// Prepare strpos filter
			$filterStrpos = false;
			if (!empty(trim($config->get('filter_strpos'))))
			{
				$filterStrpos = preg_split('/\r\n|\r|\n/', $config->get('filter_strpos'));
				$filterStrpos = array_filter(array_map('trim', $filterStrpos), function ($string) {
					return !empty($string);
				});

				if (empty($filterStrpos))
				{
					$filterStrpos = false;
				}
			}

			// Create urls arrays
			$all      = array();
			$includes = array();
			$excludes = array();

			// Add home page
			$type            = array(Text::_('COM_JLSITEMAP_TYPES_MENU'));
			$title           = $siteConfig->get('sitename');
			$link            = ($siteSef) ? rtrim(Uri::root(true), '/') . '/' : 'index.php';
			$key             = (empty($link)) ? '/' : $link;
			$loc             = rtrim(Uri::root(), '/') . $link;
			$changefreq      = $config->get('changefreq', 'weekly');
			$changefreqValue = $changefreqValues[$changefreq];
			$priority        = $config->get('priority', '0.5');
			$exclude         = false;

			$url = new Registry();
			$url->set('type', $type);
			$url->set('title', $title);
			$url->set('link', $link);
			$url->set('loc', $loc);
			$url->set('changefreq', $changefreq);
			$url->set('changefreqValue', $changefreqValue);
			$url->set('priority', $priority);
			$url->set('exclude', $exclude);

			$all [$key]        = $url;
			$includes[$key]    = $url;
			$filterMenuHomes[] = $key;

			// Add menu items to urls arrays
			foreach ($this->getMenuItems($multilanguage, $filterMenus, $siteRobots, $guestAccess) as $item)
			{
				$type            = array($item->type);
				$link            = ($siteSef) ? Route::_($item->loc) : $item->loc;
				$key             = (empty($link)) ? '/' : $link;
				$loc             = rtrim(Uri::root(), '/') . $link;
				$changefreq      = $config->get('changefreq', 'weekly');
				$changefreqValue = $changefreqValues[$changefreq];
				$priority        = $config->get('priority', '0.5');

				// Prepare exclude
				$exclude = array();
				if (!$item->home)
				{
					$exclude = ($item->exclude) ? $item->exclude : array();
					$exclude = array_merge($exclude, $this->filtering($link, $filterRaw, $filterStrpos));

					foreach ($exclude as &$value)
					{
						$value = new Registry($value);
					}
				}

				// Prepare alternates
				$alternates = array();
				if (is_array($item->alternates))
				{
					foreach ($item->alternates as $lang => $href)
					{
						$href = ($siteSef) ? Route::_($href) : $href;
						if (empty($href))
						{
							$href = '/';
						}
						if (!isset($excludes[$href]) && empty($this->filtering($href, $filterRaw, $filterStrpos)))
						{
							$alternates[$lang] = rtrim(Uri::root(), '/') . $href;
						}
					}

					if (!empty($alternates) && !isset($alternates['x-default']) && isset($alternates[$defaultLanguage]))
					{
						$alternates['x-default'] = $alternates[$defaultLanguage];
					}
				}

				// Create url Registry
				$url = new Registry();
				$url->set('type', $type);
				$url->set('title', $item->title);
				$url->set('link', $link);
				$url->set('loc', $loc);
				$url->set('changefreq', $changefreq);
				$url->set('changefreqValue', $changefreqValue);
				$url->set('priority', $priority);
				$url->set('exclude', (!empty($exclude)) ? $exclude : false);
				$url->set('alternates', (!empty($alternates)) ? $alternates : false);

				// Add url to arrays
				$all[$key] = $url;
				if (!empty($exclude))
				{
					$excludes[$key] = $url;
				}
				else
				{
					$includes[$key] = $url;
				}

				if ($item->home)
				{
					$filterMenuHomes[] = $key;
				}
				elseif (is_array($filterMenuItems) && empty($exclude))
				{
					$filterMenuItems[] = $key;
				}
			}

			// Prepare config
			$config->set('siteConfig', $siteConfig);
			$config->set('siteSef', $siteSef);
			$config->set('siteRobots', $siteRobots);
			$config->set('guestAccess', $guestAccess);
			$config->set('multilanguage', $multilanguage);
			$config->set('defaultLanguage', $defaultLanguage);
			$config->set('changefreqValues', $changefreqValues);
			$config->set('filterMenus', $filterMenus);
			$config->set('filterMenuItems', $filterMenuItems);
			$config->set('filterMenuHomes', $filterMenuHomes);
			$config->set('filterRaw', $filterRaw);
			$config->set('filterStrpos', $filterStrpos);

			// Add urls from jlsitemap plugins
			$rows = array();
			PluginHelper::importPlugin('jlsitemap');
			Factory::getApplication()->triggerEvent('onGetUrls', array(&$rows, &$config));
			foreach ($rows as $row)
			{
				$item = new Registry($row);
				if (!$loc = $item->get('loc', false)) continue;

				$type            = array($item->get('type', Text::_('COM_JLSITEMAP_TYPES_UNKNOWN')));
				$title           = $item->get('title');
				$link            = ($siteSef) ? Route::_($item->get('loc')) : $item->get('loc');
				$key             = (empty($link)) ? '/' : $link;
				$loc             = rtrim(Uri::root(), '/') . $link;
				$changefreq      = $item->get('changefreq', $config->get('changefreq', 'weekly'));
				$changefreqValue = $changefreqValues[$changefreq];
				$priority        = $item->get('priority', $config->get('priority', '0.5'));
				$lastmod         = ($item->get('lastmod', false)
					&& $item->get('lastmod') != Factory::getDbo()->getNullDate()) ?
					Factory::getDate($item->get('lastmod'))->toUnix() : false;

				// Prepare exclude
				$exclude = array();
				if (!in_array($key, $filterMenuHomes))
				{
					// Legacy old plugins
					if (is_string($item->get('exclude')))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_UNKNOWN'),
						                   'msg'  => $item->get('exclude', ''));
					}
					elseif ($item->get('exclude'))
					{
						$exclude = $item->get('exclude');
					}

					$exclude = array_merge($exclude, $this->filtering($link, $filterRaw, $filterStrpos, $filterMenuItems));

					foreach ($exclude as &$value)
					{
						$value = new Registry($value);
					}
				}

				// Prepare alternates
				$alternates = array();
				if ($item->get('alternates', false))
				{
					foreach ($item->get('alternates') as $lang => $href)
					{
						$href = ($siteSef) ? Route::_($href) : $href;
						if (empty($href))
						{
							$href = '/';
						}
						if (!isset($excludes[$href]) && empty($this->filtering($href, $filterRaw, $filterStrpos)))
						{
							$alternates[$lang] = rtrim(Uri::root(), '/') . $href;
						}
					}

					if (!empty($alternates) && !isset($alternates['x-default']) && isset($alternates[$defaultLanguage]))
					{
						$alternates['x-default'] = $alternates[$defaultLanguage];
					}
				}

				// Exist url override
				if (isset($all[$key]))
				{
					$exist = $all[$key];
					$type  = array_merge($exist->get('type'), $type);

					if (empty($title) && !empty($exist->get('title')))
					{
						$title = $exist->get('title');
					}

					if ($exist->get('changefreqValue') < $changefreqValue)
					{
						$changefreq      = $exist->get('changefreq');
						$changefreqValue = $exist->get('changefreqValue');
					}

					if (floatval($priority) > floatval($exist->get('priority')))
					{
						$priority = $exist->get('priority');
					}

					if ($exist->get('lastmod', false))
					{
						if (!$lastmod)
						{
							$lastmod = $exist->get('lastmod');
						}
						elseif ($exist->get('lastmod') > $lastmod)
						{
							$lastmod = $exist->get('lastmod');
						}
					}

					if ($exist->get('exclude'))
					{
						$exclude = array_merge($exist->get('exclude'), $exclude);
					}

					if (is_array($exist->get('alternates')))
					{
						$alternates = $alternates + $exist->get('alternates');
					}
				}

				// Create url Registry
				$url = new Registry();
				$url->set('type', $type);
				$url->set('title', $title);
				$url->set('link', $link);
				$url->set('loc', $loc);
				$url->set('changefreq', $changefreq);
				$url->set('changefreqValue', $changefreqValue);
				$url->set('priority', $priority);
				$url->set('exclude', (!empty($exclude)) ? $exclude : false);
				$url->set('lastmod', $lastmod);
				$url->set('alternates', (!empty($alternates)) ? $alternates : false);

				// Add url to arrays
				$all[$key] = $url;
				if (!empty($exclude))
				{
					$excludes[$key] = $url;

					// Exclude item if already in array (last item has priority)
					unset($includes[$key]);
				}
				else
				{
					$includes[$key] = $url;
				}
			}

			// Sort urls arrays
			ksort($all);
			ksort($includes);
			ksort($excludes);

			// Prepare urls object
			$urls           = new stdClass();
			$urls->includes = $includes;
			$urls->excludes = $excludes;
			$urls->all      = $all;

			// Set urls object
			$this->_urls = $urls;
		}

		return $this->_urls;
	}

	/**
	 * Method to get menu items array
	 *
	 * @param bool        $multilanguage Enable multilanguage
	 * @param array|false $menutypes     Menutypes filter
	 * @param string      $siteRobots    Site config robots
	 * @param array       $guestAccess   Guest access levels
	 *
	 * @return array
	 *
	 * @since 1.1.0
	 */
	protected function getMenuItems($multilanguage = false, $menutypes = false, $siteRobots = null, $guestAccess = array())
	{
		if ($this->_menuItems === null)
		{
			// Get menu items
			$db    = Factory::getDbo();
			$query = $db->getQuery(true)
				->select(array('m.id', 'm.menutype', 'm.title', 'm.link', 'm.type', 'm.published', 'm.access', 'm.home', 'm.params', 'm.language',
					'e.extension_id as component_exist', 'e.enabled as component_enabled', 'e.element as component'))
				->from($db->quoteName('#__menu', 'm'))
				->join('LEFT', '#__extensions AS e ON e.extension_id = m.component_id')
				->where('m.client_id = 0')
				->where('m.id > 1')
				->order($db->escape('m.lft') . ' ' . $db->escape('asc'));

			// Join over associations
			if ($multilanguage)
			{
				$query->select('assoc.key as association')
					->join('LEFT', '#__associations AS assoc ON assoc.id = m.id AND assoc.context = ' .
						$db->quote('com_menus.item'));
			}

			$db->setQuery($query);
			$rows = $db->loadObjectList('id');

			// Create menu items array
			$items         = array();
			$alternates    = array();
			$excludeTypes  = array('alias', 'separator', 'heading', 'url');
			$excludeStates = array(
				0  => Text::_('COM_JLSITEMAP_EXCLUDE_MENU_UNPUBLISH'),
				-2 => Text::_('COM_JLSITEMAP_EXCLUDE_MENU_TRASH'));
			foreach ($rows as $row)
			{
				$params    = new Registry($row->params);
				$home      = ($row->home && !isset($excludeStates[$row->published]));
				$component = $row->component;
				if ($row->type == 'component' && empty($row->component))
				{
					preg_match("/^index.php\?option=([a-zA-Z\-0-9_]*)/", $row->link, $matches);
					$component = (!empty($matches[1])) ? $matches[1] : 'unknown';
				}

				// Prepare loc attribute
				$loc = 'index.php?Itemid=' . $row->id;
				if (!empty($row->language) && $row->language !== '*' && $multilanguage)
				{
					$loc .= '&lang=' . $row->language;
				}

				// Prepare exclude attribute
				$exclude = array();
				if (!$home)
				{
					if ($menutypes && !empty($menutypes) && !in_array($row->menutype, $menutypes))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::_('COM_JLSITEMAP_EXCLUDE_MENU_MENUTYPES'));

					}

					if (preg_match('/noindex/', $params->get('robots', $siteRobots)))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::_('COM_JLSITEMAP_EXCLUDE_MENU_ROBOTS'));
					}

					if (isset($excludeStates[$row->published]))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => $excludeStates[$row->published]);
					}

					if (in_array($row->type, $excludeTypes))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::sprintf('COM_JLSITEMAP_EXCLUDE_MENU_SYSTEM_TYPE', $row->type));
					}

					if ($row->type == 'component' && empty($row->component_exist))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::sprintf('COM_JLSITEMAP_EXCLUDE_MENU_COMPONENT_EXIST',
							                   $component));
					}
					elseif ($row->type == 'component' && empty($row->component_enabled))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::sprintf('COM_JLSITEMAP_EXCLUDE_MENU_COMPONENT_ENABLED',
							                   $component));
					}

					if (!in_array($row->access, $guestAccess))
					{
						$exclude[] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_MENU'),
						                   'msg'  => Text::_('COM_JLSITEMAP_EXCLUDE_MENU_ACCESS'));
					}
				}

				// Prepare menu item object
				$item             = new stdClass();
				$item->loc        = $loc;
				$item->type       = Text::_('COM_JLSITEMAP_TYPES_MENU');
				$item->title      = $row->title;
				$item->home       = $home;
				$item->exclude    = (!empty($exclude)) ? $exclude : false;
				$item->alternates = ($multilanguage && !empty($row->association)) ? $row->association : false;

				// Add menu item to array
				$items[] = $item;

				// Add menu items to alternates array
				if ($multilanguage && !empty($row->association) && empty($exclude))
				{
					if (!isset($alternates[$row->association]))
					{
						$alternates[$row->association] = array();
					}

					$alternates[$row->association][$row->language] = $loc;
				};
			}

			// Add alternates to menu items
			if (!empty($alternates))
			{
				foreach ($items as &$item)
				{
					$item->alternates = ($item->alternates) ? $alternates[$item->alternates] : false;
				}
			}

			$this->_menuItems = $items;
		}

		return $this->_menuItems;
	}

	/**
	 * Method to filtering urls
	 *
	 * @param string     $link   Url
	 * @param array|bool $raw    Raw filter
	 * @param array|bool $strpos Strpos filter
	 * @param array|bool $menu   Menu items filter
	 *
	 * @return array Excludes array
	 *
	 * @since 1.3.0
	 */
	public function filtering($link = null, $raw = false, $strpos = false, $menu = false)
	{
		$exclude = array();

		// Check empty url
		if (empty($link))
		{
			$exclude['filter_null'] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER'),
			                                'msg'  => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER_NULL'));

			return $exclude;
		}

		// Filter by raw
		if ($raw && is_array($raw))
		{
			foreach ($raw as $filter)
			{
				if (mb_stripos($link, $filter, 0, 'UTF-8') !== false)
				{
					$exclude['filter_raw_' . $filter] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER'),
					                                          'msg'  => Text::sprintf('COM_JLSITEMAP_EXCLUDE_FILTER_RAW', $filter));
					break;
				}
			}
		}

		// Filter by strpos
		if ($strpos && is_array($strpos))
		{
			foreach ($strpos as $filter)
			{
				if (mb_stripos($link, $filter, 0, 'UTF-8') !== false)
				{
					$exclude['filter_strpos_' . $filter] = array('type' => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER'),
					                                             'msg'  => Text::sprintf('COM_JLSITEMAP_EXCLUDE_FILTER_STRPOS', $filter));
					break;
				}
			}
		}

		// Filter by menu
		if (is_array($menu))
		{
			$excludeMenu = true;
			foreach ($menu as $filter)
			{
				if (mb_stripos($link, $filter, 0, 'UTF-8') !== false)
				{
					$excludeMenu = false;
					break;
				}
			}

			if ($excludeMenu)
			{
				$exclude['filter_menu'] = array(
					'type' => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER'),
					'msg'  => Text::_('COM_JLSITEMAP_EXCLUDE_FILTER_MENU'));
			}
		}

		return $exclude;
	}

	/**
	 * Method to delete sitemap
	 *
	 * @return bool
	 *
	 * @since 1.4.1
	 */
	public function delete()
	{
		$file = JPATH_ROOT . '/sitemap.xml';

		return (!File::exists($file) || File::delete($file));
	}
}