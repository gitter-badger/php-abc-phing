<?php
//----------------------------------------------------------------------------------------------------------------------
use SetBased\Abc\Error\FallenException;

/**
 * Abstract parent class for tasks for optimizing resources (i.e. CSS and JS files). This class does the housekeeping
 * of resources.
 */
abstract class ResourceStoreTask extends \Task
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The extension of the resource files (i.e. .js or .css).
   *
   * @var string
   */
  protected $myExtension;

  /**
   * If set static gzipped files of the optimized/minimized resources will be created.
   *
   * @var bool
   */
  protected $myGzipFlag = false;

  /**
   * The absolute path to the parent resource dir.
   *
   * @var string
   */
  protected $myParentResourceDirFullPath;

  /**
   * If set
   * <ul>
   * <li> The mtime of optimized/minimized resource files will be inherited from its originals file.
   * <li> If two or more source files will be combined in a single resource file the mtime of this combined file will
   *      be set to the maximum mtime of the original resource files.
   * <li> When a PHP file is modified its mtime will be set to the maximum mtime of the PHP file and the referenced
   *      resource files.
   * </ul>
   *
   * @var bool
   */
  protected $myPreserveModificationTime = false;

  /**
   * The path of the resource dir (relative to the parent resource dir).
   *
   * @var string
   */
  protected $myResourceDir;

  /**
   * The absolute path to the resource dir.
   *
   * @var string
   */
  protected $myResourceDirFullPath;

  /**
   * If set stop build on errors.
   *
   * @var bool
   */
  private $myHaltOnError = true;

  /**
   * The count of resource files with the same hash. The key is the hash of the optimized resource file.
   *
   * @var int[string]
   */
  private $myHashCount;

  /**
   * The path to the parent resource dir (relative to the build dir).
   *
   * @var string
   */
  private $myParentResourceDir;

  /**
   * The names of the resource files.
   *
   * @var array
   */
  private $myResourceFileNames;

  /**
   * Array with information about file resources such as 'hash', 'content' etc.
   *
   * @var array
   */
  private $myResourceFilesInfo;

  /**
   * The ID of the fileset with resource files.
   *
   * @var string
   */
  private $myResourcesFilesetId;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param string $theExtension The extension of the resource files (i.e. .js or .css).
   */
  public function __construct($theExtension)
  {
    $this->myResourceFilesInfo = [];
    $this->myExtension         = $theExtension;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute haltOnError.
   *
   * @param $theHaltOnError
   */
  public function setHaltOnError($theHaltOnError)
  {
    $this->myHaltOnError = (boolean)$theHaltOnError;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute parentResourceDir.
   *
   * @param $theParentResourceDir string The path to the resource dir.
   */
  public function setParentResourceDir($theParentResourceDir)
  {
    $this->myParentResourceDir = $theParentResourceDir;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute resourceDir.
   *
   * @param $theResourceDir string The directory of the resource files relative tot the parent resource dir.
   */
  public function setResourceDir($theResourceDir)
  {
    $this->myResourceDir = $theResourceDir;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute resource.
   *
   * @param $theResources string The ID of the fileset with resource files.
   */
  public function setResources($theResources)
  {
    $this->myResourcesFilesetId = $theResources;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the full path with hash of an resource file.
   *
   * @param array $theFileInfo An element from {@link $myResourceFilesInfo}.
   *
   * @return string
   */
  protected function getFullPathNameWithHash($theFileInfo)
  {
    $path = $this->myResourceDirFullPath;
    $path .= '/'.$theFileInfo['hash'].'.'.$theFileInfo['ordinal'].$this->myExtension;

    return $path;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Get info about each file in the fileset.
   */
  protected function getInfoResourceFiles()
  {
    $this->logVerbose('Get resource files info.');

    $resource_dir = $this->getProject()->getReference($this->myResourcesFilesetId)->getDir($this->getProject());

    foreach ($this->myResourceFileNames as $filename)
    {
      clearstatcache();

      $path      = $resource_dir.'/'.$filename;
      $full_path = realpath($path);

      $this->store(file_get_contents($full_path), $full_path, $full_path, null);
    }

    $suc = ksort($this->myResourceFilesInfo);
    if ($suc===false) $this->logError("ksort failed.");
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the path name relative to the parent resource directory of a resource file.
   *
   * @param $thePath string The full path name of resource file.
   *
   * @return string The path name relative to the parent resource directory.
   * @throws \BuildException
   */
  protected function getPathInResources($thePath)
  {
    if (strncmp($thePath, $this->myParentResourceDirFullPath, strlen($this->myParentResourceDirFullPath))!=0)
    {
      throw new \BuildException(sprintf("Resource file '%s' is not under resource dir '%s'.",
                                        $thePath,
                                        $this->myParentResourceDirFullPath));
    }

    return substr($thePath, strlen($this->myParentResourceDirFullPath));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns path name in sources with hash from the resource info based on the path name in sources.
   * If can't find, return path name in sources.
   *
   * @param string $theBaseUrl          Parent resource folder.
   * @param string $theResourcePathName Path name to the resource.
   *
   * @return string
   */
  protected function getPathInResourcesWithHash($theBaseUrl, $theResourcePathName)
  {
    foreach ($this->myResourceFilesInfo as $info)
    {
      if ($info['path_name_in_sources']===$theBaseUrl.'/'.$theResourcePathName.$this->myExtension)
      {
        return $info['path_name_in_sources_with_hash'];
      }
    }

    return $theResourcePathName;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the resource info based on the full path of the resource.
   *
   * @param $theFullPathName
   *
   * @return array
   * @throws BuildException
   */
  protected function getResourceInfo($theFullPathName)
  {
    foreach ($this->myResourceFilesInfo as $info)
    {
      if ($info['full_path_name']===$theFullPathName)
      {
        return $info;
      }
    }

    $this->logError("Unknown resource file '%s'.", $theFullPathName);

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the resource info based on the full path of the resource.
   *
   * @param $theFullPathNameWithHash
   *
   * @return array
   * @throws BuildException
   */
  protected function getResourceInfoByHash($theFullPathNameWithHash)
  {
    foreach ($this->myResourceFilesInfo as $info)
    {
      if ($info['full_path_name_with_hash']===$theFullPathNameWithHash)
      {
        return $info;
      }
    }

    $this->logError("Unknown resource file '%s'.", $theFullPathNameWithHash);

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns an array with info about all resources.
   *
   * @return array
   */
  protected function getResourcesInfo()
  {
    return $this->myResourceFilesInfo;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Prints an error message and depending on HaltOnError throws an exception.
   *
   * @param mixed ...$param The arguments as for [sprintf](http://php.net/manual/function.sprintf.php)
   *
   * @throws \BuildException
   */
  protected function logError()
  {
    $args   = func_get_args();
    $format = array_shift($args);

    foreach ($args as &$arg)
    {
      if (!is_scalar($arg)) $arg = var_export($arg, true);
    }

    if ($this->myHaltOnError) throw new \BuildException(vsprintf($format, $args));
    else $this->log(vsprintf($format, $args), \Project::MSG_ERR);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Prints an info message.
   *
   * @param mixed ...$param The arguments as for [sprintf](http://php.net/manual/function.sprintf.php)
   */
  protected function logInfo()
  {
    $args   = func_get_args();
    $format = array_shift($args);

    foreach ($args as &$arg)
    {
      if (!is_scalar($arg)) $arg = var_export($arg, true);
    }

    $this->log(vsprintf($format, $args), \Project::MSG_INFO);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Prints an verbose level message.
   *
   * @param mixed ...$param The arguments as for [sprintf](http://php.net/manual/function.sprintf.php)
   */
  protected function logVerbose()
  {
    $args   = func_get_args();
    $format = array_shift($args);

    foreach ($args as &$arg)
    {
      if (!is_scalar($arg)) $arg = var_export($arg, true);
    }

    $this->log(vsprintf($format, $args), \Project::MSG_VERBOSE);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Minimizes JavaScript or CSS code.
   *
   * @param string $theResource     The JavaScript or CSS code.
   * @param string $theFullPathName The full pathname of the JavaScript or CSS file.
   *
   * @return string The minimized JavaScript or CSS code.
   */
  abstract protected function minimizeResource($theResource, $theFullPathName);

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Get info about all source, resource files and directories.
   */
  protected function prepareProjectData()
  {
    $this->logVerbose('Get source and resource file names.');

    // Get file list form the project by fileset ID.
    $resources                 = $this->getProject()->getReference($this->myResourcesFilesetId);
    $this->myResourceFileNames = $resources->getDirectoryScanner($this->getProject())->getIncludedFiles();

    // Get full path name of resource dir.
    $this->myParentResourceDirFullPath = realpath($resources->getDir($this->getProject()).'/'.$this->myParentResourceDir);

    // Get full path name of resource dir.
    $this->myResourceDirFullPath = realpath($this->myParentResourceDirFullPath.'/'.$this->myResourceDir);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Enhance all elements in {@link $this->myResourceFilesInfo} with an ordinal to prevent hash collisions. (In most
   * cases this ordinal will be 0.)
   *
   * @throws BuildException
   */
  protected function saveOptimizedResourceFiles()
  {
    $this->logInfo("Saving minimized files.");

    foreach ($this->myResourceFilesInfo as $file_info)
    {
      $file_info['full_path_name_with_hash']       = $this->getFullPathNameWithHash($file_info);
      $file_info['path_name_in_sources_with_hash'] = $this->getPathInResources($file_info['full_path_name_with_hash']);

      $bytes = file_put_contents($file_info['full_path_name_with_hash'], $file_info['content_opt']);
      if ($bytes===false) $this->logError("Unable to write to file '%s'.", $file_info['full_path_name_with_hash']);

      if (isset($file_info['full_path_name']))
      {
        // If required preserve mtime.
        if ($this->myPreserveModificationTime)
        {
          $status = touch($file_info['full_path_name_with_hash'], $file_info['mtime']);
          if ($status===false)
          {
            $this->logError("Unable to set mtime of file '%s'", $file_info['full_path_name_with_hash']);
          }
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the mode of a file.
   *
   * @param $theDestinationFilename string The full file name of destination file.
   * @param $theReferenceFilename
   *
   * @throws BuildException
   */
  protected function setFilePermissions($theDestinationFilename, $theReferenceFilename)
  {
    clearstatcache();
    $perms = fileperms($theReferenceFilename);
    if ($perms===false) $this->logError("Unable to get permissions of file '%s'.", $theReferenceFilename);

    $status = chmod($theDestinationFilename, $perms);
    if ($status===false) $this->logError("Unable to set permissions for file '%s'.", $theDestinationFilename);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Copy the mtime form the source file to the destination file.
   *
   * @param $theDestinationFilename string The full file name of destination file.
   * @param $theNewMtime
   *
   * @throws BuildException
   */
  protected function setModificationTime($theDestinationFilename, $theNewMtime)
  {
    $status = touch($theDestinationFilename, $theNewMtime);
    if ($status===false)
    {
      $this->logError("Unable to set mtime of file '%s'", $theDestinationFilename);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Minimize resource, create hash based on optimized content. Add resource info into array.
   *
   * @param string       $theResource     The (actual content) of the resource.
   * @param string       $theFullPathName The full pathname of the file where the resource is stored.
   * @param string|array $theParts        Array with original resource files.
   * @param string       $theGetInfoBy    Flag for look in source with hash or without
   *
   * @return array
   * @throws BuildException
   */
  protected function store($theResource, $theFullPathName, $theParts, $theGetInfoBy)
  {
    if (isset($theFullPathName)) $this->logInfo("Minimizing '%s'.", $theFullPathName);

    $content_opt = $this->minimizeResource($theResource, $theFullPathName);

    // @todo Ignore *.main.js files.

    $file_info                                   = [];
    $file_info['hash']                           = md5($content_opt);
    $file_info['content_raw']                    = $theResource;
    $file_info['content_opt']                    = $content_opt;
    $file_info['ordinal']                        = isset($this->myHashCount[$file_info['hash']]) ? $this->myHashCount[$file_info['hash']]++ : $this->myHashCount[$file_info['hash']] = 0;
    $file_info['full_path_name_with_hash']       = $this->myResourceDirFullPath.'/'.
      $file_info['hash'].'.'.$file_info['ordinal'].$this->myExtension;
    $file_info['path_name_in_sources_with_hash'] = $this->getPathInResources($file_info['full_path_name_with_hash']);

    if (isset($theFullPathName))
    {
      $file_info['full_path_name']       = $theFullPathName;
      $file_info['path_name_in_sources'] = $this->getPathInResources($theFullPathName);
    }

    if (isset($theParts))
    {
      $file_info['mtime'] = $this->getMaxMtime($theParts, $theGetInfoBy);
    }

    $this->myResourceFilesInfo[] = $file_info;

    return $file_info;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes resource files that have been optimized/minimized.
   */
  protected function unlinkResourceFiles()
  {
    $this->logInfo("Removing resource files.");

    foreach ($this->myResourceFilesInfo as $file_info)
    {
      if (isset($file_info['full_path_name_with_hash']) && isset($file_info['full_path_name']))
      {
        // Resource file has an optimized/minimized version. Remove the original file.
        $this->logInfo("Removing '%s'.", $file_info['full_path_name']);
        if (file_exists($file_info['full_path_name'])) unlink($file_info['full_path_name']);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Return mtime if $theParts is one file or return max mtime if array
   *
   * @param array|string $theParts
   * @param string       $theGetInfoBy Flag for look in source with hash or without hash.
   *
   * @return int mtime
   */
  private function getMaxMtime($theParts, $theGetInfoBy)
  {
    $mtime = [];
    if (is_array($theParts))
    {
      foreach ($theParts as $part)
      {
        switch ($theGetInfoBy)
        {
          case 'full_path_name_with_hash':
            $info = $this->getResourceInfoByHash($part);
            break;

          case 'full_path_name':
            $info = $this->getResourceInfo($part);
            break;

          default:
            throw new FallenException('$theGetInfoBy', $theGetInfoBy);
        }
        $mtime[] = $info['mtime'];
      }
    }
    else
    {
      $mtime[] = filemtime($theParts);
    }

    return max($mtime);
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
