<?php

declare(strict_types=1);

namespace Drupal\aqto_static;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles generation of static sites.
 */
final class StaticGenerator
{

  private readonly EntityTypeManagerInterface $entityTypeManager;
  private readonly RendererInterface $renderer;
  private readonly FileSystemInterface $fileSystem;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    RendererInterface $renderer,
    FileSystemInterface $fileSystem
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
    $this->fileSystem = $fileSystem;
  }

  public function generateStaticSite(array $node_ids, string $directory_name): void
  {
    $original_session =     \Drupal::request()->getSession();
    $node_storage = $this->entityTypeManager->getStorage('node');
    $nodes = $node_storage->loadMultiple($node_ids);

    $output_directory = "public://{$directory_name}/";
    $this->fileSystem->prepareDirectory($output_directory, FileSystemInterface::CREATE_DIRECTORY);

    // Simulate a web request to capture the entire page context
    $original_request = \Drupal::request();
    $request = Request::create('/');
    \Drupal::requestStack()->push($request);
    $orIGinal_account =  \Drupal::currentUser();
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $url = $node->toUrl()->setAbsolute()->toString();
        $request->server->set('REQUEST_URI', $url);
        $response = $this->simulateRequest($url);
        $html_content = $response->getContent();
        $html_content = $this->adjustHtmlContent($html_content, $output_directory);
        // Check if node is front page node from system config.
        $front_page_node_id = \Drupal::config('system.site')->get('page.front');
        if ($node->id() == $front_page_node_id) {
          $filename = 'index.html';
        } else {
          $filename = $node->id() . '.html';
        }
        file_put_contents($this->fileSystem->realpath($output_directory) . '/' . $filename, $html_content);
      }
    }

    // Now lets copyDrupalAssets to ensure
    // all the assets are available.
    $this->copyDrupalAssets($output_directory);

    // Restore the original request
    \Drupal::requestStack()->pop();
    \Drupal::requestStack()->push($original_request);
    // Make sure session sset

    \Drupal::currentUser()->setAccount($orIGinal_account);
    $request->setSession($original_session);
  }

  private function simulateRequest(string $path): Response
  {
    // Check for recursive simulation.
    if (\Drupal::request()->getRequestUri() === $path) {
      throw new \RuntimeException("Recursive request simulation detected for path: {$path}");
    }

    // Create the request.
    $request = Request::create($path, 'GET');

    // Ensure a session is initialized if needed.
    if (!$request->hasSession()) {
      $session = new \Symfony\Component\HttpFoundation\Session\Session();
      $request->setSession($session);
    }

    // Handle the request with the HTTP kernel.
    $kernel = \Drupal::service('http_kernel');
    $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST, false);

    return $response;
  }
  private function copyDrupalAssets(string $output_directory)
  {
    // Define the directories where Drupal stores aggregated CSS and JS.
    $source_css_directory = 'public://css';
    $source_js_directory = 'public://js';

    // Define target directories in the static output
    $target_css_directory = $this->fileSystem->realpath($output_directory) . '/css';
    $target_js_directory = $this->fileSystem->realpath($output_directory) . '/js';

    // Ensure the target directories exist or create them
    $this->fileSystem->prepareDirectory($target_css_directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->prepareDirectory($target_js_directory, FileSystemInterface::CREATE_DIRECTORY);

    // Copy the files from the source to target directories
    $this->copyFilesFromDirectory($source_css_directory, $target_css_directory);
    $this->copyFilesFromDirectory($source_js_directory, $target_js_directory);
  }

  private function copyFilesFromDirectory($source_directory, $target_directory)
  {
    $directory = \Drupal::service('file_system')->realpath($source_directory);
    $iterator = new \DirectoryIterator($directory);
    foreach ($iterator as $fileinfo) {
      if (!$fileinfo->isDot() && !$fileinfo->isDir()) {
        $source_file = $fileinfo->getPathname();
        $target_file = $target_directory . '/' . $fileinfo->getFilename();

        // Copy the file if it doesn't exist or if it's newer than the target
        if (!file_exists($target_file) || filemtime($source_file) > filemtime($target_file)) {
          copy($source_file, $target_file);
        }
      }
    }
  }

  private function adjustHtmlContent(string $html_content, string $output_directory)
  {
    $patterns = [
      '/"\/sites\/default\/files\/css\/(.*?)"/' => '"css/$1"',
      '/"\/sites\/default\/files\/js\/(.*?)"/' => '"js/$1"'
    ];

    foreach ($patterns as $pattern => $replacement) {
      $html_content = preg_replace($pattern, $replacement, $html_content);
    }

    return $html_content;
  }
}
