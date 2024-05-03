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

        $filename = $this->fileSystem->realpath($output_directory) . '/' . $node->id() . '.html';
        file_put_contents($filename, $response->getContent());
      }
    }

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

  private function copyAssets(string $output_directory)
  {
    // Define source directories for assets
    $source_css_directory = DRUPAL_ROOT . '/themes/custom/mytheme/css'; // Adjust path as needed
    $source_js_directory = DRUPAL_ROOT . '/themes/custom/mytheme/js'; // Adjust path as needed

    // Define target directories
    $target_css_directory = $this->fileSystem->realpath($output_directory) . '/css';
    $target_js_directory = $this->fileSystem->realpath($output_directory) . '/js';

    // Ensure target directories exist
    $this->fileSystem->prepareDirectory($target_css_directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->prepareDirectory($target_js_directory, FileSystemInterface::CREATE_DIRECTORY);

    // Copy files
    foreach (['css' => $source_css_directory, 'js' => $source_js_directory] as $type => $source_directory) {
      foreach (new \DirectoryIterator($source_directory) as $fileinfo) {
        if (!$fileinfo->isDot() && !$fileinfo->isDir()) {
          copy($fileinfo->getPathname(), "${'target_' .$type . '_directory'}/" . $fileinfo->getFilename());
        }
      }
    }
  }
}
