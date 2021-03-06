<?php
//----------------------------------------------------------------------------------------------------------------------
require_once 'ResourceStoreTask.php';

//----------------------------------------------------------------------------------------------------------------------
/**
 * Abstract parent class for optimizing/minimizing resources (i.e. CSS and JS files) and modifying references to those
 * resources.
 */
abstract class OptimizeResourceTask extends \ResourceStoreTask
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Map from the original references to resource files to new references (which includes a hash).
   *
   * @var array
   */
  private $myReplacePairs = [];

  /**
   * The names of the sources files.
   *
   * @var array
   */
  private $mySourceFileNames;

  /**
   * Info about source files.
   *
   * @var array
   */
  private $mySourceFilesInfo;

  /**
   * The ID of the fileset with sources.
   *
   * @var string
   */
  private $mySourcesFilesetId;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Main method of this Phing task.
   */
  public function main()
  {
    // Get base info about project.
    $this->prepareProjectData();

    // Get all info about source files.
    $this->getInfoSourceFiles();

    // Get all info about resource files.
    $this->getInfoResourceFiles();

    // Prepare all place holders.
    $this->preparePlaceHolders();

    // Replace references to resource files with references to optimized/minimized resource files.
    $this->processingSourceFiles();

    // Compress and rename files with hash.
    $this->processResourceFiles();

    // Create pre-compressed versions of the optimized/minimized resource files.
    if ($this->myGzipFlag) $this->gzipCompressOptimizedResourceFiles();

    // Remove original resource files that are optimized/minimized.
    $this->unlinkResourceFiles();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute GZip.
   *
   * @param $theGzipFlag bool.
   */
  public function setGzip($theGzipFlag = false)
  {
    $this->myGzipFlag = (boolean)$theGzipFlag;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute preserveLastModified.
   *
   * @param $thePreserveLastModifiedFlag bool
   */
  public function setPreserveLastModified($thePreserveLastModifiedFlag)
  {
    $this->myPreserveModificationTime = (boolean)$thePreserveLastModifiedFlag;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Setter for XML attribute sources.
   *
   * @param $theSources string The ID of the fileset with source files.
   */
  public function setSources($theSources)
  {
    $this->mySourcesFilesetId = $theSources;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns an array with classes and namespaces defined in PHP code.
   *
   * @param string $thePhpCode The PHP code.
   *
   * @return array
   */
  protected function getClasses($thePhpCode)
  {
    $tokens = token_get_all($thePhpCode);

    $mode      = '';
    $namespace = '';
    $classes   = [];
    foreach ($tokens as $i => $token)
    {
      // If this token is the namespace declaring, then flag that the next tokens will be the namespace name
      if (is_array($token) && $token[0]==T_NAMESPACE)
      {
        $mode      = 'namespace';
        $namespace = '';
        continue;
      }

      // If this token is the class declaring, then flag that the next tokens will be the class name
      if (is_array($token) && $token[0]==T_CLASS)
      {
        $mode = 'class';
        continue;
      }

      // While we're grabbing the namespace name...
      if ($mode=='namespace')
      {
        // If the token is a string or the namespace separator...
        if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR]))
        {
          //Append the token's value to the name of the namespace
          $namespace .= $token[1];
        }
        elseif (is_array($token) && $token[0]==T_WHITESPACE)
        {
          // Ignore whitespace.
        }
        elseif ($token===';')
        {
          // If the token is the semicolon, then we're done with the namespace declaration
          $mode = '';
        }
        elseif ($token==='{')
        {
          throw new LogicException('Bracketed syntax for namespace not supported.');
        }
        else
        {
          throw new LogicException("Unexpected token %s", print_r($token, true));
        }
      }

      // While we're grabbing the class name...
      if ($mode=='class')
      {
        // If the token is a string, it's the name of the class
        if (is_array($token) && $token[0]==T_STRING)
        {
          // Store the token's value as the class name
          $classes[$token[2]] = ['namespace' => $namespace,
                                 'class'     => $token[1],
                                 'line'      => $token[2]];

          $mode = '';
        }
      }
    }

    return $classes;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Get info about all source, resource files and directories.
   */
  protected function prepareProjectData()
  {
    parent::prepareProjectData();

    // Get file list form the project by fileset ID.
    $sources                 = $this->getProject()->getReference($this->mySourcesFilesetId);
    $this->mySourceFileNames = $sources->getDirectoryScanner($this->getProject())->getIncludedFiles();
    $suc                     = ksort($this->mySourceFileNames);
    if ($suc===false) $this->logError("ksort failed.");
  }
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * In PHP code replaces references to resource files (i.e. CSS or JS files) with references to the optimized versions
   * of the resource files.
   *
   * @param string $theFilename The filename with the PHP code.
   * @param string $thePhpCode  The PHP code.
   *
   * @return string The modified PHP code.
   */
  abstract protected function processPhpSourceFile($theFilename, $thePhpCode);

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Gets full path for each source file in the source fileset.
   */
  private function getInfoSourceFiles()
  {
    $this->logVerbose('Get resource files info.');

    // Get the base dir of the sources.
    $dir = $this->getProject()->getReference($this->mySourcesFilesetId)->getDir($this->getProject());

    foreach ($this->mySourceFileNames as $theFileName)
    {
      $filename                            = $dir.'/'.$theFileName;
      $real_path                           = realpath($filename);
      $this->mySourceFilesInfo[$real_path] = $filename;
    }

    $suc = ksort($this->mySourceFilesInfo);
    if ($suc===false) $this->logError("ksort failed.");
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the maximum mtime of a sources file and its referenced optimized/minimized resource files.
   *
   * @param $theSourceFilename string The name of the source file.
   * @param $theContent        string The content of the source file with renamed references to resource files.
   *
   * @return int The max mtime.
   */
  private function getMaxModificationTime($theSourceFilename, $theContent)
  {
    $times = [];

    $time = filemtime($theSourceFilename);
    if ($time===false) $this->logError("Unable to get mtime of file '%s'.", $theSourceFilename);
    $times[] = $time;

    $resource_files_info = $this->getResourceFilesInSource($theContent);
    foreach ($resource_files_info as $resource_file_info)
    {
      $info = $this->getResourceInfoByHash($resource_file_info['full_path_name_with_hash']);
      $time = $info['mtime'];
      if ($time===false) $this->logError("Unable to get mtime for file '%s'.", $resource_file_info);
      $times[] = $time;
    }

    return max($times);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns an array with info about resource files referenced from a source file.
   *
   * @param $theSourceFileContent string Content of updated source file.
   *
   * @return array
   */
  private function getResourceFilesInSource($theSourceFileContent)
  {
    $resource_files = [];
    foreach ($this->getResourcesInfo() as $file_info)
    {
      if (strpos($theSourceFileContent, $file_info['path_name_in_sources_with_hash'])!==false)
      {
        $resource_files[] = $file_info;
      }
    }

    return $resource_files;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Compresses optimized/minimized resource files with gzip.
   *
   * @throws BuildException
   */
  private function gzipCompressOptimizedResourceFiles()
  {
    $this->logInfo('Gzip compressing files.');

    foreach ($this->getResourcesInfo() as $file_info)
    {
      $this->logVerbose("Gzip compressing file '%s' to '%s'.",
                        $file_info['full_path_name_with_hash'],
                        $file_info['full_path_name_with_hash'].'.gz');

      // Get data from the file.
      $data_opt = file_get_contents($file_info['full_path_name_with_hash']);
      if ($data_opt===false)
      {
        $this->logError("Can not read the file '%s' or file does not exist.", $file_info['full_path_name_with_hash']);
      }

      // Compress data with gzip
      $data_gzip = gzencode($data_opt, 9);
      if ($data_gzip===false)
      {
        $this->logError("Can not write the file '%s' or file does not exist.",
                        $file_info['full_path_name_with_hash'].'.gz');
      }

      if (strlen($data_gzip)<strlen($data_opt))
      {
        // Write data to the file.
        $status = file_put_contents($file_info['full_path_name_with_hash'].'.gz', $data_gzip);
        if ($status===false)
        {
          $this->logError("Unable to write to file '%s", $file_info['full_path_name_with_hash'].'.gz');
        }

        // If required preserve mtime.
        if ($this->myPreserveModificationTime)
        {
          $info  = $this->getResourceInfoByHash($file_info['full_path_name_with_hash']);
          $mtime = $info['mtime'];
          $this->setModificationTime($file_info['full_path_name_with_hash'].'.gz', $mtime);
        }

        // If required preserve file permissions.
        clearstatcache();
        if ($this->myPreserveModificationTime && fileperms($file_info['full_path_name_with_hash'])!==false)
        {
          $this->setFilePermissions($file_info['full_path_name_with_hash'].'.gz', $file_info['full_path_name_with_hash']);
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Prepares place holders with resource file names and references to optimized/minimized resource file names (with
   * includes a hash).
   */
  private function preparePlaceHolders()
  {
    $this->logVerbose('Prepare place holders.');

    foreach ($this->getResourcesInfo() as $file_info)
    {
      $this->myReplacePairs["'".$file_info['path_name_in_sources']."'"] = "'".$file_info['path_name_in_sources_with_hash']."'";
      $this->myReplacePairs['"'.$file_info['path_name_in_sources'].'"'] = '"'.$file_info['path_name_in_sources_with_hash'].'"';

      if (isset($file_info['path_name_in_sources_alternative']))
      {
        $this->myReplacePairs["'".$file_info['path_name_in_sources_alternative']."'"] = "'".$file_info['path_name_in_sources_with_hash']."'";
        $this->myReplacePairs['"'.$file_info['path_name_in_sources_alternative'].'"'] = '"'.$file_info['path_name_in_sources_with_hash'].'"';
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Minimizes/optimizes the resource files and removes original resources files from the build dir.
   */
  private function processResourceFiles()
  {
    // Save all optimized resource files.
    $this->saveOptimizedResourceFiles();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Replaces references to resource files in source files with the hashed filename op the optimized/minimized
   * resources.
   */
  private function processingSourceFiles()
  {
    $this->logVerbose('Replace references to resource files with references to optimized/minimized resource files.');

    foreach ($this->mySourceFilesInfo as $source_filename)
    {
      $this->logVerbose("Processing %s.", $source_filename);

      $content = file_get_contents($source_filename);
      if ($content===false) $this->logError("Unable to read file '%s'.", $source_filename);

      $new_content = $content;

      if (strncmp($content, '<?php', 5)===0)
      {
        // Source is a PHP file.
        $new_content = $this->processPhpSourceFile($source_filename, $new_content);
      }

      $new_content = strtr($new_content, $this->myReplacePairs);

      if ($content!=$new_content)
      {
        $time = null;

        // If required determine the latest modification time of the source file and its referenced resource files.
        if ($this->myPreserveModificationTime)
        {
          $time = $this->getMaxModificationTime($source_filename, $new_content);
        }

        // Write sources file with modified references to resource files.
        $status = file_put_contents($source_filename, $new_content);
        if ($status===false) $this->logError("Updating file '%s' failed.", $source_filename);
        $this->logInfo("Updated file '%s'.", $source_filename);

        // If required set the mtime to the latest modification time of the source file and its referenced resource
        // files.
        if ($this->myPreserveModificationTime)
        {
          $status = touch($source_filename, $time);
          if ($status===false) $this->logError("Unable to set mtime for file '%s'.", $source_filename);
        }
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
