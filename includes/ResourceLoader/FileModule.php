<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Trevor Parscal
 * @author Roan Kattouw
 */

namespace MediaWiki\ResourceLoader;

use CSSJanus;
use Exception;
use ExtensionRegistry;
use FileContentsHasher;
use InvalidArgumentException;
use LogicException;
use MediaWiki\Languages\LanguageFallback;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use OutputPage;
use RuntimeException;
use Wikimedia\Minify\CSSMin;
use Wikimedia\RequestTimeout\TimeoutException;

/**
 * Module based on local JavaScript/CSS files.
 *
 * The following public methods can query the database:
 *
 * - getDefinitionSummary / … / Module::getFileDependencies.
 * - getVersionHash / getDefinitionSummary / … / Module::getFileDependencies.
 * - getStyles / Module::saveFileDependencies.
 *
 * @ingroup ResourceLoader
 * @see $wgResourceModules
 * @since 1.17
 */
class FileModule extends Module {
	/** @var string Local base path, see __construct() */
	protected $localBasePath = '';

	/** @var string Remote base path, see __construct() */
	protected $remoteBasePath = '';

	/**
	 * @var array<int,string|FilePath> List of JavaScript file paths to always include
	 */
	protected $scripts = [];

	/**
	 * @var array<string,array<int,string|FilePath>> Lists of JavaScript files by language code
	 */
	protected $languageScripts = [];

	/**
	 * @var array<string,array<int,string|FilePath>> Lists of JavaScript files by skin name
	 */
	protected $skinScripts = [];

	/**
	 * @var array<int,string|FilePath> List of paths to JavaScript files to include in debug mode
	 */
	protected $debugScripts = [];

	/**
	 * @var array<int,string|FilePath> List of CSS file files to always include
	 */
	protected $styles = [];

	/**
	 * @var array<string,array<int,string|FilePath>> Lists of CSS files by skin name
	 */
	protected $skinStyles = [];

	/**
	 * Packaged files definition, to bundle and make available client-side via `require()`.
	 *
	 * @see FileModule::expandPackageFiles()
	 * @var null|array
	 * @phan-var null|array<int,string|FilePath|array{main?:bool,name?:string,file?:string|FilePath,type?:string,content?:mixed,config?:array,callback?:callable,callbackParam?:mixed,versionCallback?:callable}>
	 */
	protected $packageFiles = null;

	/**
	 * @var array Expanded versions of $packageFiles, lazy-computed by expandPackageFiles();
	 *  keyed by context hash
	 */
	private $expandedPackageFiles = [];

	/**
	 * @var array Further expanded versions of $expandedPackageFiles, lazy-computed by
	 *   getPackageFiles(); keyed by context hash
	 */
	private $fullyExpandedPackageFiles = [];

	/**
	 * @var string[] List of modules this module depends on
	 */
	protected $dependencies = [];

	/**
	 * @var null|string File name containing the body of the skip function
	 */
	protected $skipFunction = null;

	/**
	 * @var string[] List of message keys used by this module
	 */
	protected $messages = [];

	/** @var array<int|string,string|FilePath> List of the named templates used by this module */
	protected $templates = [];

	/** @var null|string Name of group to load this module in */
	protected $group = null;

	/** @var bool Link to raw files in debug mode */
	protected $debugRaw = true;

	/** @var string[] */
	protected $targets = [ 'desktop' ];

	/** @var bool Whether CSSJanus flipping should be skipped for this module */
	protected $noflip = false;

	/** @var bool Whether this module requires the client to support ES6 */
	protected $es6 = false;

	/**
	 * @var bool Whether getStyleURLsForDebug should return raw file paths,
	 * or return load.php urls
	 */
	protected $hasGeneratedStyles = false;

	/**
	 * @var string[] Place where readStyleFile() tracks file dependencies
	 */
	protected $localFileRefs = [];

	/**
	 * @var string[] Place where readStyleFile() tracks file dependencies for non-existent files.
	 * Used in tests to detect missing dependencies.
	 */
	protected $missingLocalFileRefs = [];

	/**
	 * @var VueComponentParser|null Lazy-created by getVueComponentParser()
	 */
	protected $vueComponentParser = null;

	/**
	 * Constructs a new module from an options array.
	 *
	 * @param array $options See $wgResourceModules for the available options.
	 * @param string|null $localBasePath Base path to prepend to all local paths in $options.
	 *     Defaults to $IP
	 * @param string|null $remoteBasePath Base path to prepend to all remote paths in $options.
	 *     Defaults to $wgResourceBasePath
	 */
	public function __construct(
		array $options = [],
		string $localBasePath = null,
		string $remoteBasePath = null
	) {
		// Flag to decide whether to automagically add the mediawiki.template module
		$hasTemplates = false;
		// localBasePath and remoteBasePath both have unbelievably long fallback chains
		// and need to be handled separately.
		[ $this->localBasePath, $this->remoteBasePath ] =
			self::extractBasePaths( $options, $localBasePath, $remoteBasePath );

		// Extract, validate and normalise remaining options
		foreach ( $options as $member => $option ) {
			switch ( $member ) {
				// Lists of file paths
				case 'scripts':
				case 'debugScripts':
				case 'styles':
				case 'packageFiles':
					$this->{$member} = is_array( $option ) ? $option : [ $option ];
					break;
				case 'templates':
					$hasTemplates = true;
					$this->{$member} = is_array( $option ) ? $option : [ $option ];
					break;
				// Collated lists of file paths
				case 'languageScripts':
				case 'skinScripts':
				case 'skinStyles':
					if ( !is_array( $option ) ) {
						throw new InvalidArgumentException(
							"Invalid collated file path list error. " .
							"'$option' given, array expected."
						);
					}
					foreach ( $option as $key => $value ) {
						if ( !is_string( $key ) ) {
							throw new InvalidArgumentException(
								"Invalid collated file path list key error. " .
								"'$key' given, string expected."
							);
						}
						$this->{$member}[$key] = is_array( $value ) ? $value : [ $value ];
					}
					break;
				case 'deprecated':
					$this->deprecated = $option;
					break;
				// Lists of strings
				case 'dependencies':
				case 'messages':
				case 'targets':
					// Normalise
					$option = array_values( array_unique( (array)$option ) );
					sort( $option );

					$this->{$member} = $option;
					break;
				// Single strings
				case 'group':
				case 'skipFunction':
					$this->{$member} = (string)$option;
					break;
				// Single booleans
				case 'debugRaw':
				case 'noflip':
				case 'es6':
					$this->{$member} = (bool)$option;
					break;
			}
		}
		// In future this should be expanded to cover modules using packageFiles as well.
		$isModernCode = $this->requiresES6();
		if ( $isModernCode ) {
			// If targets omitted, modern code should automatically default to mobile+desktop targets.
			$isNotMobileTargeted = !in_array( 'mobile', $this->targets );
			// Modern JavaScript should never be restricted to desktop-only (see T323542)
			if ( $isNotMobileTargeted ) {
				// Add the mobile target to these modules.
				$this->targets[] = 'mobile';
				$targetsSpecified = isset( $options['targets'] );
				// If the user intentionally tried to avoid adding to mobile log a warning.
				if ( $targetsSpecified ) {
					$this->getLogger()->warning( "When 'es6' is enabled, module will automatically target mobile.", [
						'module' => $this->getName(),
					] );
				}
			}
		}
		if ( isset( $options['scripts'] ) && isset( $options['packageFiles'] ) ) {
			throw new InvalidArgumentException( "A module may not set both 'scripts' and 'packageFiles'" );
		}
		if ( isset( $options['packageFiles'] ) && isset( $options['skinScripts'] ) ) {
			throw new InvalidArgumentException( "Options 'skinScripts' and 'packageFiles' cannot be used together." );
		}
		if ( $hasTemplates ) {
			$this->dependencies[] = 'mediawiki.template';
			// Ensure relevant template compiler module gets loaded
			foreach ( $this->templates as $alias => $templatePath ) {
				if ( is_int( $alias ) ) {
					$alias = $this->getPath( $templatePath );
				}
				$suffix = explode( '.', $alias );
				$suffix = end( $suffix );
				$compilerModule = 'mediawiki.template.' . $suffix;
				if ( $suffix !== 'html' && !in_array( $compilerModule, $this->dependencies ) ) {
					$this->dependencies[] = $compilerModule;
				}
			}
		}
	}

	/**
	 * Extract a pair of local and remote base paths from module definition information.
	 * Implementation note: the amount of global state used in this function is staggering.
	 *
	 * @param array $options Module definition
	 * @param string|null $localBasePath Path to use if not provided in module definition. Defaults
	 *     to $IP
	 * @param string|null $remoteBasePath Path to use if not provided in module definition. Defaults
	 *     to $wgResourceBasePath
	 * @return string[] [ localBasePath, remoteBasePath ]
	 */
	public static function extractBasePaths(
		array $options = [],
		$localBasePath = null,
		$remoteBasePath = null
	) {
		global $IP;
		// The different ways these checks are done, and their ordering, look very silly,
		// but were preserved for backwards-compatibility just in case. Tread lightly.

		if ( $remoteBasePath === null ) {
			$remoteBasePath = MediaWikiServices::getInstance()->getMainConfig()
				->get( MainConfigNames::ResourceBasePath );
		}

		if ( isset( $options['remoteExtPath'] ) ) {
			$extensionAssetsPath = MediaWikiServices::getInstance()->getMainConfig()
				->get( MainConfigNames::ExtensionAssetsPath );
			$remoteBasePath = $extensionAssetsPath . '/' . $options['remoteExtPath'];
		}

		if ( isset( $options['remoteSkinPath'] ) ) {
			$stylePath = MediaWikiServices::getInstance()->getMainConfig()
				->get( MainConfigNames::StylePath );
			$remoteBasePath = $stylePath . '/' . $options['remoteSkinPath'];
		}

		if ( array_key_exists( 'localBasePath', $options ) ) {
			$localBasePath = (string)$options['localBasePath'];
		}

		if ( array_key_exists( 'remoteBasePath', $options ) ) {
			$remoteBasePath = (string)$options['remoteBasePath'];
		}

		if ( $remoteBasePath === '' ) {
			// If MediaWiki is installed at the document root (not recommended),
			// then wgScriptPath is set to the empty string by the installer to
			// ensure safe concatenating of file paths (avoid "/" + "/foo" being "//foo").
			// However, this also means the path itself can be an invalid URI path,
			// as those must start with a slash. Within ResourceLoader, we will not
			// do such primitive/unsafe slash concatenation and use URI resolution
			// instead, so beyond this point, to avoid fatal errors in CSSMin::resolveUrl(),
			// do a best-effort support for docroot installs by casting this to a slash.
			$remoteBasePath = '/';
		}

		return [ $localBasePath ?? $IP, $remoteBasePath ];
	}

	/**
	 * Gets all scripts for a given context concatenated together.
	 *
	 * @param Context $context Context in which to generate script
	 * @return string|array JavaScript code for $context, or package files data structure
	 */
	public function getScript( Context $context ) {
		$deprecationScript = $this->getDeprecationInformation( $context );
		$packageFiles = $this->getPackageFiles( $context );
		if ( $packageFiles !== null ) {
			foreach ( $packageFiles['files'] as &$file ) {
				if ( $file['type'] === 'script+style' ) {
					$file['content'] = $file['content']['script'];
					$file['type'] = 'script';
				}
			}
			if ( $deprecationScript ) {
				$mainFile =& $packageFiles['files'][$packageFiles['main']];
				$mainFile['content'] = $deprecationScript . $mainFile['content'];
			}
			return $packageFiles;
		}

		$files = $this->getScriptFiles( $context );
		return $deprecationScript . $this->readScriptFiles( $files );
	}

	/**
	 * @param Context $context
	 * @return string[] URLs
	 */
	public function getScriptURLsForDebug( Context $context ) {
		$rl = $context->getResourceLoader();
		$config = $this->getConfig();
		$server = $config->get( MainConfigNames::Server );

		$urls = [];
		foreach ( $this->getScriptFiles( $context ) as $file ) {
			$url = OutputPage::transformResourcePath( $config, $this->getRemotePath( $file ) );
			// Expand debug URL in case we are another wiki's module source (T255367)
			$url = $rl->expandUrl( $server, $url );
			$urls[] = $url;
		}
		return $urls;
	}

	/**
	 * @return bool
	 */
	public function supportsURLLoading() {
		// If package files are involved, don't support URL loading, because that breaks
		// scoped require() functions
		return $this->debugRaw && !$this->packageFiles;
	}

	/**
	 * Get all styles for a given context.
	 *
	 * @param Context $context
	 * @return string[] CSS code for $context as an associative array mapping media type to CSS text.
	 */
	public function getStyles( Context $context ) {
		$styles = $this->readStyleFiles(
			$this->getStyleFiles( $context ),
			$context
		);

		$packageFiles = $this->getPackageFiles( $context );
		if ( $packageFiles !== null ) {
			foreach ( $packageFiles['files'] as $fileName => $file ) {
				if ( $file['type'] === 'script+style' ) {
					$style = $this->processStyle(
						$file['content']['style'],
						$file['content']['styleLang'],
						$fileName,
						$context
					);
					$styles['all'] = ( $styles['all'] ?? '' ) . "\n" . $style;
				}
			}
		}

		// Track indirect file dependencies so that StartUpModule can check for
		// on-disk file changes to any of this files without having to recompute the file list
		$this->saveFileDependencies( $context, $this->localFileRefs );

		return $styles;
	}

	/**
	 * @param Context $context
	 * @return string[][] Lists of URLs by media type
	 */
	public function getStyleURLsForDebug( Context $context ) {
		if ( $this->hasGeneratedStyles ) {
			// Do the default behaviour of returning a url back to load.php
			// but with only=styles.
			return parent::getStyleURLsForDebug( $context );
		}
		// Our module consists entirely of real css files,
		// in debug mode we can load those directly.
		$urls = [];
		foreach ( $this->getStyleFiles( $context ) as $mediaType => $list ) {
			$urls[$mediaType] = [];
			foreach ( $list as $file ) {
				$urls[$mediaType][] = OutputPage::transformResourcePath(
					$this->getConfig(),
					$this->getRemotePath( $file )
				);
			}
		}
		return $urls;
	}

	/**
	 * Get message keys used by this module.
	 *
	 * @return string[] List of message keys
	 */
	public function getMessages() {
		return $this->messages;
	}

	/**
	 * Get the name of the group this module should be loaded in.
	 *
	 * @return null|string Group name
	 */
	public function getGroup() {
		return $this->group;
	}

	/**
	 * Get names of modules this module depends on.
	 *
	 * @param Context|null $context
	 * @return string[] List of module names
	 */
	public function getDependencies( Context $context = null ) {
		return $this->dependencies;
	}

	/**
	 * Helper method for getting a file.
	 *
	 * @param string $localPath The path to the resource to load
	 * @param string $type The type of resource being loaded (for error reporting only)
	 * @return string
	 */
	private function getFileContents( $localPath, $type ) {
		if ( !is_file( $localPath ) ) {
			throw new RuntimeException( "$type file not found or not a file: \"$localPath\"" );
		}
		return $this->stripBom( file_get_contents( $localPath ) );
	}

	/**
	 * @return null|string
	 */
	public function getSkipFunction() {
		if ( !$this->skipFunction ) {
			return null;
		}
		$localPath = $this->getLocalPath( $this->skipFunction );
		return $this->getFileContents( $localPath, 'skip function' );
	}

	public function requiresES6() {
		return $this->es6;
	}

	/**
	 * Disable module content versioning.
	 *
	 * This class uses getDefinitionSummary() instead, to avoid filesystem overhead
	 * involved with building the full module content inside a startup request.
	 *
	 * @return bool
	 */
	public function enableModuleContentVersion() {
		return false;
	}

	/**
	 * Helper method for getDefinitionSummary.
	 *
	 * @param Context $context
	 * @return string Hash
	 */
	private function getFileHashes( Context $context ) {
		$files = [];

		$styleFiles = $this->getStyleFiles( $context );
		foreach ( $styleFiles as $paths ) {
			$files = array_merge( $files, $paths );
		}

		// Extract file paths for package files
		// Optimisation: Use foreach() and isset() instead of array_map/array_filter.
		// This is a hot code path, called by StartupModule for thousands of modules.
		$expandedPackageFiles = $this->expandPackageFiles( $context );
		$packageFiles = [];
		if ( $expandedPackageFiles ) {
			foreach ( $expandedPackageFiles['files'] as $fileInfo ) {
				if ( isset( $fileInfo['filePath'] ) ) {
					$packageFiles[] = $fileInfo['filePath'];
				}
			}
		}

		// Merge all the file paths we were able discover directly from the module definition.
		// This is the primary list of direct-dependent files for this module.
		$files = array_merge(
			$files,
			$packageFiles,
			$this->scripts,
			$this->templates,
			$context->getDebug() ? $this->debugScripts : [],
			$this->getLanguageScripts( $context->getLanguage() ),
			self::tryForKey( $this->skinScripts, $context->getSkin(), 'default' )
		);
		if ( $this->skipFunction ) {
			$files[] = $this->skipFunction;
		}

		// Expand these local paths into absolute file paths
		$files = array_map( [ $this, 'getLocalPath' ], $files );

		// Add any lazily discovered file dependencies from previous module builds.
		// These are added last because they are already absolute file paths.
		$files = array_merge( $files, $this->getFileDependencies( $context ) );

		// Filter out any duplicates. Typically introduced by getFileDependencies() which
		// may lazily re-discover a primary file.
		$files = array_unique( $files );

		// Don't return array keys or any other form of file path here, only the hashes.
		// Including file paths would needlessly cause global cache invalidation when files
		// move on disk or if e.g. the MediaWiki directory name changes.
		// Anything where order is significant is already detected by the definition summary.
		return FileContentsHasher::getFileContentsHash( $files );
	}

	/**
	 * Get the definition summary for this module.
	 *
	 * @param Context $context
	 * @return array
	 */
	public function getDefinitionSummary( Context $context ) {
		$summary = parent::getDefinitionSummary( $context );

		$options = [];
		foreach ( [
			// The following properties are omitted because they don't affect the module response:
			// - localBasePath (Per T104950; Changes when absolute directory name changes. If
			//    this affects 'scripts' and other file paths, getFileHashes accounts for that.)
			// - remoteBasePath (Per T104950)
			// - dependencies (provided via startup module)
			// - targets
			// - group (provided via startup module)
			'scripts',
			'debugScripts',
			'styles',
			'languageScripts',
			'skinScripts',
			'skinStyles',
			'messages',
			'templates',
			'skipFunction',
			'debugRaw',
		] as $member ) {
			$options[$member] = $this->{$member};
		}

		$packageFiles = $this->expandPackageFiles( $context );
		if ( $packageFiles ) {
			// Extract the minimum needed:
			// - The 'main' pointer (included as-is).
			// - The 'files' array, simplified to only which files exist (the keys of
			//   this array), and something that represents their non-file content.
			//   For packaged files that reflect files directly from disk, the
			//   'getFileHashes' method tracks their content already.
			//   It is important that the keys of the $packageFiles['files'] array
			//   are preserved, as they do affect the module output.
			$packageFiles['files'] = array_map( static function ( $fileInfo ) {
				return $fileInfo['definitionSummary'] ?? ( $fileInfo['content'] ?? null );
			}, $packageFiles['files'] );
		}

		$summary[] = [
			'options' => $options,
			'packageFiles' => $packageFiles,
			'fileHashes' => $this->getFileHashes( $context ),
			'messageBlob' => $this->getMessageBlob( $context ),
		];

		$lessVars = $this->getLessVars( $context );
		if ( $lessVars ) {
			$summary[] = [ 'lessVars' => $lessVars ];
		}

		return $summary;
	}

	/**
	 * @return VueComponentParser
	 */
	protected function getVueComponentParser() {
		if ( $this->vueComponentParser === null ) {
			$this->vueComponentParser = new VueComponentParser;
		}
		return $this->vueComponentParser;
	}

	/**
	 * @param string|FilePath $path
	 * @return string
	 */
	protected function getPath( $path ) {
		if ( $path instanceof FilePath ) {
			return $path->getPath();
		}

		return $path;
	}

	/**
	 * @param string|FilePath $path
	 * @return string
	 */
	protected function getLocalPath( $path ) {
		if ( $path instanceof FilePath ) {
			if ( $path->getLocalBasePath() !== null ) {
				return $path->getLocalPath();
			}
			$path = $path->getPath();
		}

		return "{$this->localBasePath}/$path";
	}

	/**
	 * @param string|FilePath $path
	 * @return string
	 */
	protected function getRemotePath( $path ) {
		if ( $path instanceof FilePath ) {
			if ( $path->getRemoteBasePath() !== null ) {
				return $path->getRemotePath();
			}
			$path = $path->getPath();
		}

		if ( $this->remoteBasePath === '/' ) {
			return "/$path";
		} else {
			return "{$this->remoteBasePath}/$path";
		}
	}

	/**
	 * Infer the stylesheet language from a stylesheet file path.
	 *
	 * @since 1.22
	 * @param string $path
	 * @return string The stylesheet language name
	 */
	public function getStyleSheetLang( $path ) {
		return preg_match( '/\.less$/i', $path ) ? 'less' : 'css';
	}

	/**
	 * Infer the file type from a package file path.
	 * @param string $path
	 * @return string 'script', 'script-vue', or 'data'
	 */
	public static function getPackageFileType( $path ) {
		if ( preg_match( '/\.json$/i', $path ) ) {
			return 'data';
		}
		if ( preg_match( '/\.vue$/i', $path ) ) {
			return 'script-vue';
		}
		return 'script';
	}

	/**
	 * Collates styles file paths by 'media' option (or 'all' if 'media' is not set)
	 *
	 * @param array $list List of file paths in any combination of index/path
	 *     or path/options pairs
	 * @return string[][] List of collated file paths
	 */
	private static function collateStyleFilesByMedia( array $list ) {
		$collatedFiles = [];
		foreach ( $list as $key => $value ) {
			if ( is_int( $key ) ) {
				// File name as the value
				if ( !isset( $collatedFiles['all'] ) ) {
					$collatedFiles['all'] = [];
				}
				$collatedFiles['all'][] = $value;
			} elseif ( is_array( $value ) ) {
				// File name as the key, options array as the value
				$optionValue = $value['media'] ?? 'all';
				if ( !isset( $collatedFiles[$optionValue] ) ) {
					$collatedFiles[$optionValue] = [];
				}
				$collatedFiles[$optionValue][] = $key;
			}
		}
		return $collatedFiles;
	}

	/**
	 * Get a list of element that match a key, optionally using a fallback key.
	 *
	 * @param array[] $list List of lists to select from
	 * @param string $key Key to look for in $list
	 * @param string|null $fallback Key to look for in $list if $key doesn't exist
	 * @return array List of elements from $list which matched $key or $fallback,
	 *  or an empty list in case of no match
	 */
	protected static function tryForKey( array $list, $key, $fallback = null ) {
		if ( isset( $list[$key] ) && is_array( $list[$key] ) ) {
			return $list[$key];
		} elseif ( is_string( $fallback )
			&& isset( $list[$fallback] )
			&& is_array( $list[$fallback] )
		) {
			return $list[$fallback];
		}
		return [];
	}

	/**
	 * Get script file paths for this module, in order of proper execution.
	 *
	 * @param Context $context
	 * @return array<int,string|FilePath> File paths
	 */
	private function getScriptFiles( Context $context ): array {
		// List in execution order: scripts, languageScripts, skinScripts, debugScripts.
		// Documented at MediaWiki\MainConfigSchema::ResourceModules.
		$files = array_merge(
			$this->scripts,
			$this->getLanguageScripts( $context->getLanguage() ),
			self::tryForKey( $this->skinScripts, $context->getSkin(), 'default' )
		);
		if ( $context->getDebug() ) {
			$files = array_merge( $files, $this->debugScripts );
		}

		return array_unique( $files, SORT_REGULAR );
	}

	/**
	 * Get the set of language scripts for the given language,
	 * possibly using a fallback language.
	 *
	 * @param string $lang
	 * @return array<int,string|FilePath> File paths
	 */
	private function getLanguageScripts( string $lang ): array {
		$scripts = self::tryForKey( $this->languageScripts, $lang );
		if ( $scripts ) {
			return $scripts;
		}

		// Optimization: Avoid initialising and calling into language services
		// for the majority of modules that don't use this option.
		if ( $this->languageScripts ) {
			$fallbacks = MediaWikiServices::getInstance()
				->getLanguageFallback()
				->getAll( $lang, LanguageFallback::MESSAGES );
			foreach ( $fallbacks as $lang ) {
				$scripts = self::tryForKey( $this->languageScripts, $lang );
				if ( $scripts ) {
					return $scripts;
				}
			}
		}

		return [];
	}

	public function setSkinStylesOverride( array $moduleSkinStyles ): void {
		$moduleName = $this->getName();
		foreach ( $moduleSkinStyles as $skinName => $overrides ) {
			// If a module provides overrides for a skin, and that skin also provides overrides
			// for the same module, then the module has precedence.
			if ( isset( $this->skinStyles[$skinName] ) ) {
				continue;
			}

			// If $moduleName in ResourceModuleSkinStyles is preceded with a '+', the defined style
			// files will be added to 'default' skinStyles, otherwise 'default' will be ignored.
			if ( isset( $overrides[$moduleName] ) ) {
				$paths = (array)$overrides[$moduleName];
				$styleFiles = [];
			} elseif ( isset( $overrides['+' . $moduleName] ) ) {
				$paths = (array)$overrides['+' . $moduleName];
				$styleFiles = isset( $this->skinStyles['default'] ) ?
					(array)$this->skinStyles['default'] :
					[];
			} else {
				continue;
			}

			// Add new file paths, remapping them to refer to our directories and not use settings
			// from the module we're modifying, which come from the base definition.
			[ $localBasePath, $remoteBasePath ] = self::extractBasePaths( $overrides );

			foreach ( $paths as $path ) {
				$styleFiles[] = new FilePath( $path, $localBasePath, $remoteBasePath );
			}

			$this->skinStyles[$skinName] = $styleFiles;
		}
	}

	/**
	 * Get a list of file paths for all styles in this module, in order of proper inclusion.
	 *
	 * @internal Exposed only for use by structure phpunit tests.
	 * @param Context $context
	 * @return array<string,array<int,string|FilePath>> Map from media type to list of file paths
	 */
	public function getStyleFiles( Context $context ) {
		return array_merge_recursive(
			self::collateStyleFilesByMedia( $this->styles ),
			self::collateStyleFilesByMedia(
				self::tryForKey( $this->skinStyles, $context->getSkin(), 'default' )
			)
		);
	}

	/**
	 * Gets a list of file paths for all skin styles in the module used by
	 * the skin.
	 *
	 * @param string $skinName The name of the skin
	 * @return array A list of file paths collated by media type
	 */
	protected function getSkinStyleFiles( $skinName ) {
		return self::collateStyleFilesByMedia(
			self::tryForKey( $this->skinStyles, $skinName )
		);
	}

	/**
	 * Gets a list of file paths for all skin style files in the module,
	 * for all available skins.
	 *
	 * @return array A list of file paths collated by media type
	 */
	protected function getAllSkinStyleFiles() {
		$skinFactory = MediaWikiServices::getInstance()->getSkinFactory();
		$styleFiles = [];

		$internalSkinNames = array_keys( $skinFactory->getInstalledSkins() );
		$internalSkinNames[] = 'default';

		foreach ( $internalSkinNames as $internalSkinName ) {
			$styleFiles = array_merge_recursive(
				$styleFiles,
				$this->getSkinStyleFiles( $internalSkinName )
			);
		}

		return $styleFiles;
	}

	/**
	 * Returns all style files and all skin style files used by this module.
	 *
	 * @return array
	 */
	public function getAllStyleFiles() {
		$collatedStyleFiles = array_merge_recursive(
			self::collateStyleFilesByMedia( $this->styles ),
			$this->getAllSkinStyleFiles()
		);

		$result = [];

		foreach ( $collatedStyleFiles as $styleFiles ) {
			foreach ( $styleFiles as $styleFile ) {
				$result[] = $this->getLocalPath( $styleFile );
			}
		}

		return $result;
	}

	/**
	 * Get the contents of a list of JavaScript files. Helper for getScript().
	 *
	 * @param array<int,string|FilePath> $scripts List of file paths to scripts to read, remap and concatenate
	 * @return string Concatenated JavaScript code
	 */
	private function readScriptFiles( array $scripts ) {
		if ( !$scripts ) {
			return '';
		}
		$js = '';
		foreach ( array_unique( $scripts, SORT_REGULAR ) as $fileName ) {
			$localPath = $this->getLocalPath( $fileName );
			$contents = $this->getFileContents( $localPath, 'script' );
			$js .= ResourceLoader::ensureNewline( $contents );
		}
		return $js;
	}

	/**
	 * Read the contents of a list of CSS files and remap and concatenate these.
	 *
	 * @internal This is considered a private method. Exposed for internal use by WebInstallerOutput.
	 * @param array<string,array<int,string|FilePath>> $styles Map of media type to file paths
	 * @param Context $context
	 * @return array<string,string> Map of combined CSS code, keyed by media type
	 */
	public function readStyleFiles( array $styles, Context $context ) {
		if ( !$styles ) {
			return [];
		}
		foreach ( $styles as $media => $files ) {
			$uniqueFiles = array_unique( $files, SORT_REGULAR );
			$styleFiles = [];
			foreach ( $uniqueFiles as $file ) {
				$styleFiles[] = $this->readStyleFile( $file, $context );
			}
			$styles[$media] = implode( "\n", $styleFiles );
		}
		return $styles;
	}

	/**
	 * Read and process a style file. Reads a file from disk and runs it through processStyle().
	 *
	 * This method can be used as a callback for array_map()
	 *
	 * @internal
	 * @param string|FilePath $path Path of style file to read
	 * @param Context $context
	 * @return string CSS code
	 */
	protected function readStyleFile( $path, Context $context ) {
		$localPath = $this->getLocalPath( $path );
		$style = $this->getFileContents( $localPath, 'style' );
		$styleLang = $this->getStyleSheetLang( $localPath );

		return $this->processStyle( $style, $styleLang, $path, $context );
	}

	/**
	 * Process a CSS/LESS string.
	 *
	 * This method performs the following processing steps:
	 * - LESS compilation (if $styleLang = 'less')
	 * - RTL flipping with CSSJanus (if getFlip() returns true)
	 * - Registration of references to local files in $localFileRefs and $missingLocalFileRefs
	 * - URL remapping and data URI embedding
	 *
	 * @internal
	 * @param string $style CSS or LESS code
	 * @param string $styleLang Language of $style code ('css' or 'less')
	 * @param string|FilePath $path Path to code file, used for resolving relative file paths
	 * @param Context $context
	 * @return string Processed CSS code
	 */
	protected function processStyle( $style, $styleLang, $path, Context $context ) {
		$localPath = $this->getLocalPath( $path );
		$remotePath = $this->getRemotePath( $path );

		if ( $styleLang === 'less' ) {
			$style = $this->compileLessString( $style, $localPath, $context );
			$this->hasGeneratedStyles = true;
		}

		if ( $this->getFlip( $context ) ) {
			$style = CSSJanus::transform(
				$style,
				/* $swapLtrRtlInURL = */ true,
				/* $swapLeftRightInURL = */ false
			);
		}

		$localDir = dirname( $localPath );
		$remoteDir = dirname( $remotePath );
		// Get and register local file references
		$localFileRefs = CSSMin::getLocalFileReferences( $style, $localDir );
		foreach ( $localFileRefs as $file ) {
			if ( is_file( $file ) ) {
				$this->localFileRefs[] = $file;
			} else {
				$this->missingLocalFileRefs[] = $file;
			}
		}
		// Don't cache this call. remap() ensures data URIs embeds are up to date,
		// and urls contain correct content hashes in their query string. (T128668)
		return CSSMin::remap( $style, $localDir, $remoteDir, true );
	}

	/**
	 * Get whether CSS for this module should be flipped
	 * @param Context $context
	 * @return bool
	 */
	public function getFlip( Context $context ) {
		return $context->getDirection() === 'rtl' && !$this->noflip;
	}

	/**
	 * Get target(s) for the module, eg ['desktop'] or ['desktop', 'mobile']
	 *
	 * @return string[]
	 */
	public function getTargets() {
		return $this->targets;
	}

	/**
	 * Get the module's load type.
	 *
	 * @since 1.28
	 * @return string
	 */
	public function getType() {
		$canBeStylesOnly = !(
			// All options except 'styles', 'skinStyles' and 'debugRaw'
			$this->scripts
			|| $this->debugScripts
			|| $this->templates
			|| $this->languageScripts
			|| $this->skinScripts
			|| $this->dependencies
			|| $this->messages
			|| $this->skipFunction
			|| $this->packageFiles
		);
		return $canBeStylesOnly ? self::LOAD_STYLES : self::LOAD_GENERAL;
	}

	/**
	 * Compile a LESS string into CSS.
	 *
	 * Keeps track of all used files and adds them to localFileRefs.
	 *
	 * @since 1.35
	 * @param string $style LESS source to compile
	 * @param string $stylePath File path of LESS source, used for resolving relative file paths
	 * @param Context $context Context in which to generate script
	 * @return string CSS source
	 */
	protected function compileLessString( $style, $stylePath, Context $context ) {
		static $cache;
		// @TODO: dependency injection
		if ( !$cache ) {
			$cache = ObjectCache::getLocalServerInstance( CACHE_ANYTHING );
		}

		$skinName = $context->getSkin();
		$skinImportPaths = ExtensionRegistry::getInstance()->getAttribute( 'SkinLessImportPaths' );
		$importDirs = [];
		if ( isset( $skinImportPaths[ $skinName ] ) ) {
			$importDirs[] = $skinImportPaths[ $skinName ];
		}

		$vars = $this->getLessVars( $context );
		// Construct a cache key from a hash of the LESS source, and a hash digest
		// of the LESS variables used for compilation.
		ksort( $vars );
		$compilerParams = [
			'vars' => $vars,
			'importDirs' => $importDirs,
		];
		$key = $cache->makeGlobalKey(
			'resourceloader-less',
			'v1',
			hash( 'md4', $style ),
			hash( 'md4', serialize( $compilerParams ) )
		);

		// If we got a cached value, we have to validate it by getting a checksum of all the
		// files that were loaded by the parser and ensuring it matches the cached entry's.
		$data = $cache->get( $key );
		if (
			!$data ||
			$data['hash'] !== FileContentsHasher::getFileContentsHash( $data['files'] )
		) {
			$compiler = $context->getResourceLoader()->getLessCompiler( $vars, $importDirs );

			$css = $compiler->parse( $style, $stylePath )->getCss();
			// T253055: store the implicit dependency paths in a form relative to any install
			// path so that multiple version of the application can share the cache for identical
			// less stylesheets. This also avoids churn during application updates.
			$files = $compiler->AllParsedFiles();
			$data = [
				'css'   => $css,
				'files' => Module::getRelativePaths( $files ),
				'hash'  => FileContentsHasher::getFileContentsHash( $files )
			];
			$cache->set( $key, $data, $cache::TTL_DAY );
		}

		foreach ( Module::expandRelativePaths( $data['files'] ) as $path ) {
			$this->localFileRefs[] = $path;
		}

		return $data['css'];
	}

	/**
	 * Get content of named templates for this module.
	 *
	 * @return array<string,string> Templates mapping template alias to content
	 */
	public function getTemplates() {
		$templates = [];

		foreach ( $this->templates as $alias => $templatePath ) {
			// Alias is optional
			if ( is_int( $alias ) ) {
				$alias = $this->getPath( $templatePath );
			}
			$localPath = $this->getLocalPath( $templatePath );
			$content = $this->getFileContents( $localPath, 'template' );

			$templates[$alias] = $this->stripBom( $content );
		}
		return $templates;
	}

	/**
	 * Internal helper for use by getPackageFiles(), getFileHashes() and getDefinitionSummary().
	 *
	 * This expands the 'packageFiles' definition into something that's (almost) the right format
	 * for getPackageFiles() to return. It expands shorthands, resolves config vars, and handles
	 * summarising any non-file data for getVersionHash(). For file-based data, getFileHashes()
	 * handles it instead, which also ends up in getDefinitionSummary().
	 *
	 * What it does not do is reading the actual contents of any specified files, nor invoking
	 * the computation callbacks. Those things are done by getPackageFiles() instead to improve
	 * backend performance by only doing this work when the module response is needed, and not
	 * when merely computing the version hash for StartupModule, or when checking
	 * If-None-Match headers for a HTTP 304 response.
	 *
	 * @param Context $context
	 * @return array|null
	 * @phan-return array{main:?string,files:array[]}|null
	 */
	private function expandPackageFiles( Context $context ) {
		$hash = $context->getHash();
		if ( isset( $this->expandedPackageFiles[$hash] ) ) {
			return $this->expandedPackageFiles[$hash];
		}
		if ( $this->packageFiles === null ) {
			return null;
		}
		$expandedFiles = [];
		$mainFile = null;

		foreach ( $this->packageFiles as $key => $fileInfo ) {
			if ( !is_array( $fileInfo ) ) {
				$fileInfo = [ 'name' => $fileInfo, 'file' => $fileInfo ];
			}
			if ( !isset( $fileInfo['name'] ) ) {
				$msg = "Missing 'name' key in package file info for module '{$this->getName()}'," .
					" offset '{$key}'.";
				$this->getLogger()->error( $msg );
				throw new LogicException( $msg );
			}
			$fileName = $this->getPath( $fileInfo['name'] );

			// Infer type from alias if needed
			$type = $fileInfo['type'] ?? self::getPackageFileType( $fileName );
			$expanded = [ 'type' => $type ];
			if ( !empty( $fileInfo['main'] ) ) {
				$mainFile = $fileName;
				if ( $type !== 'script' && $type !== 'script-vue' ) {
					$msg = "Main file in package must be of type 'script', module " .
						"'{$this->getName()}', main file '{$mainFile}' is '{$type}'.";
					$this->getLogger()->error( $msg );
					throw new LogicException( $msg );
				}
			}

			// Perform expansions (except 'file' and 'callback'), creating one of these keys:
			// - 'content': literal value.
			// - 'filePath': content to be read from a file.
			// - 'callback': content computed by a callable.
			if ( isset( $fileInfo['content'] ) ) {
				$expanded['content'] = $fileInfo['content'];
			} elseif ( isset( $fileInfo['file'] ) ) {
				$expanded['filePath'] = $fileInfo['file'];
			} elseif ( isset( $fileInfo['callback'] ) ) {
				// If no extra parameter for the callback is given, use null.
				$expanded['callbackParam'] = $fileInfo['callbackParam'] ?? null;

				if ( !is_callable( $fileInfo['callback'] ) ) {
					$msg = "Invalid 'callback' for module '{$this->getName()}', file '{$fileName}'.";
					$this->getLogger()->error( $msg );
					throw new LogicException( $msg );
				}
				if ( isset( $fileInfo['versionCallback'] ) ) {
					if ( !is_callable( $fileInfo['versionCallback'] ) ) {
						throw new LogicException( "Invalid 'versionCallback' for "
							. "module '{$this->getName()}', file '{$fileName}'."
						);
					}

					// Execute the versionCallback with the same arguments that
					// would be given to the callback
					$callbackResult = ( $fileInfo['versionCallback'] )(
						$context,
						$this->getConfig(),
						$expanded['callbackParam']
					);
					if ( $callbackResult instanceof FilePath ) {
						$expanded['filePath'] = $callbackResult;
					} else {
						$expanded['definitionSummary'] = $callbackResult;
					}
					// Don't invoke 'callback' here as it may be expensive (T223260).
					$expanded['callback'] = $fileInfo['callback'];
				} else {
					// Else go ahead invoke callback with its arguments.
					$callbackResult = ( $fileInfo['callback'] )(
						$context,
						$this->getConfig(),
						$expanded['callbackParam']
					);
					if ( $callbackResult instanceof FilePath ) {
						$expanded['filePath'] = $callbackResult;
					} else {
						$expanded['content'] = $callbackResult;
					}
				}
			} elseif ( isset( $fileInfo['config'] ) ) {
				if ( $type !== 'data' ) {
					$msg = "Key 'config' only valid for data files. "
						. " Module '{$this->getName()}', file '{$fileName}' is '{$type}'.";
					$this->getLogger()->error( $msg );
					throw new LogicException( $msg );
				}
				$expandedConfig = [];
				foreach ( $fileInfo['config'] as $configKey => $var ) {
					$expandedConfig[ is_numeric( $configKey ) ? $var : $configKey ] = $this->getConfig()->get( $var );
				}
				$expanded['content'] = $expandedConfig;
			} elseif ( !empty( $fileInfo['main'] ) ) {
				// [ 'name' => 'foo.js', 'main' => true ] is shorthand
				$expanded['filePath'] = $fileName;
			} else {
				$msg = "Incomplete definition for module '{$this->getName()}', file '{$fileName}'. "
					. "One of 'file', 'content', 'callback', or 'config' must be set.";
				$this->getLogger()->error( $msg );
				throw new LogicException( $msg );
			}

			$expandedFiles[$fileName] = $expanded;
		}

		if ( $expandedFiles && $mainFile === null ) {
			// The first package file that is a script is the main file
			foreach ( $expandedFiles as $path => $file ) {
				if ( $file['type'] === 'script' || $file['type'] === 'script-vue' ) {
					$mainFile = $path;
					break;
				}
			}
		}

		$result = [
			'main' => $mainFile,
			'files' => $expandedFiles
		];

		$this->expandedPackageFiles[$hash] = $result;
		return $result;
	}

	/**
	 * Resolve the package files definition and generates the content of each package file.
	 *
	 * @param Context $context
	 * @return array|null Package files data structure, see ResourceLoaderModule::getScript()
	 */
	public function getPackageFiles( Context $context ) {
		if ( $this->packageFiles === null ) {
			return null;
		}
		$hash = $context->getHash();
		if ( isset( $this->fullyExpandedPackageFiles[ $hash ] ) ) {
			return $this->fullyExpandedPackageFiles[ $hash ];
		}
		$expandedPackageFiles = $this->expandPackageFiles( $context );

		// Expand file contents
		foreach ( $expandedPackageFiles['files'] as $fileName => &$fileInfo ) {
			// Turn any 'filePath' or 'callback' key into actual 'content',
			// and remove the key after that. The callback could return a
			// ResourceLoaderFilePath object; if that happens, fall through
			// to the 'filePath' handling.
			if ( isset( $fileInfo['callback'] ) ) {
				$callbackResult = ( $fileInfo['callback'] )(
					$context,
					$this->getConfig(),
					$fileInfo['callbackParam']
				);
				if ( $callbackResult instanceof FilePath ) {
					// Fall through to the filePath handling code below
					$fileInfo['filePath'] = $callbackResult;
				} else {
					$fileInfo['content'] = $callbackResult;
				}
				unset( $fileInfo['callback'] );
			}
			// Only interpret 'filePath' if 'content' hasn't been set already.
			// This can happen if 'versionCallback' provided 'filePath',
			// while 'callback' provides 'content'. In that case both are set
			// at this point. The 'filePath' from 'versionCallback' in that case is
			// only to inform getDefinitionSummary().
			if ( !isset( $fileInfo['content'] ) && isset( $fileInfo['filePath'] ) ) {
				$localPath = $this->getLocalPath( $fileInfo['filePath'] );
				$content = $this->getFileContents( $localPath, 'package' );
				if ( $fileInfo['type'] === 'data' ) {
					$content = json_decode( $content );
				}
				$fileInfo['content'] = $content;
				unset( $fileInfo['filePath'] );
			}
			if ( $fileInfo['type'] === 'script-vue' ) {
				try {
					$parsedComponent = $this->getVueComponentParser()->parse(
						// @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset False positive
						$fileInfo['content'],
						[ 'minifyTemplate' => !$context->getDebug() ]
					);
				} catch ( TimeoutException $e ) {
					throw $e;
				} catch ( Exception $e ) {
					$msg = "Error parsing file '$fileName' in module '{$this->getName()}': " .
						$e->getMessage();
					$this->getLogger()->error( $msg );
					throw new RuntimeException( $msg );
				}
				$encodedTemplate = json_encode( $parsedComponent['template'] );
				if ( $context->getDebug() ) {
					// Replace \n (backslash-n) with space + backslash-newline in debug mode
					// We only replace \n if not preceded by a backslash, to avoid breaking '\\n'
					$encodedTemplate = preg_replace( '/(?<!\\\\)\\\\n/', " \\\n", $encodedTemplate );
					// Expand \t to real tabs in debug mode
					$encodedTemplate = strtr( $encodedTemplate, [ "\\t" => "\t" ] );
				}
				$fileInfo['content'] = [
					'script' => $parsedComponent['script'] .
						";\nmodule.exports.template = $encodedTemplate;",
					'style' => $parsedComponent['style'] ?? '',
					'styleLang' => $parsedComponent['styleLang'] ?? 'css'
				];
				$fileInfo['type'] = 'script+style';
			}

			// Not needed for client response, exists for use by getDefinitionSummary().
			unset( $fileInfo['definitionSummary'] );
			// Not needed for client response, used by callbacks only.
			unset( $fileInfo['callbackParam'] );
		}

		$this->fullyExpandedPackageFiles[ $hash ] = $expandedPackageFiles;
		return $expandedPackageFiles;
	}

	/**
	 * Takes an input string and removes the UTF-8 BOM character if present
	 *
	 * We need to remove these after reading a file, because we concatenate our files and
	 * the BOM character is not valid in the middle of a string.
	 * We already assume UTF-8 everywhere, so this should be safe.
	 *
	 * @param string $input
	 * @return string Input minus the initial BOM char
	 */
	protected function stripBom( $input ) {
		if ( str_starts_with( $input, "\xef\xbb\xbf" ) ) {
			return substr( $input, 3 );
		}
		return $input;
	}
}

/** @deprecated since 1.39 */
class_alias( FileModule::class, 'ResourceLoaderFileModule' );
