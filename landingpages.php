<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Framework\File\CsvFile;
use Grav\Framework\File\Formatter\CsvFormatter;
use Grav\Framework\Flex\Flex;
use Grav\Framework\Flex\FlexObject;
use Grav\Framework\Flex\Interfaces\FlexCollectionInterface;
use Grav\Framework\Flex\Interfaces\FlexDirectoryInterface;
use Grav\Plugin\Directus\Utility\DirectusUtility;
use Grav\Common\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use function Couchbase\defaultDecoder;

/**
 * Class LandingpagesPlugin
 * @package Grav\Plugin
 */
class LandingpagesPlugin extends Plugin
{

    /**
     * @var Flex
     */
    protected $flex;

    /**
     * @var FlexCollectionInterface
     */
    protected $collection;

    /**
     * @var FlexDirectoryInterface
     */
    protected $directory;

    /**
     * @var DirectusUtility
     */
    protected $directusUtil;


    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Uncomment following line when plugin requires Grav < 1.7
                // ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
    * Composer autoload.
    *is
    * @return ClassLoader
    */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function onPageInitialized() {

        /** @var Flex $flex */
        $this->flex = Grav::instance()->get('flex');

        $this->directusUtil = new DirectusUtility(
            $this->config["plugins.directus"]['directus']['directusAPIUrl'],
            $this->grav,
            '',
            '',
            $this->config["plugins.directus"]['directus']['token'],
            true
        );

        $this->processWebHooks($this->grav['uri']->route());

        $requestedUri = $this->grav['uri']->path();

        $uriParams = array_merge(array_filter(explode('/', $requestedUri)));
        $urlParams = $this->grav['uri']->query(null, true);

        $this->validationAudience($urlParams);
        unset($urlParams['audience']);

        if(isset($uriParams[0]) && $uriParams[0] === $this->config()['landingpages']['entryslug'] && isset($_GET['audience']) ){
            $redirectUrl = $requestedUri.'/'.$_GET['audience'] . ($urlParams ? '?'.http_build_query($urlParams) : '');
            $validateRedirectUrl = $this->validationRedirect($redirectUrl);
            $this->redirect( $validateRedirectUrl, 301);
        }
        elseif (isset($uriParams[0]) && $uriParams[0] === $this->config()['landingpages']['entryslug'] && !isset($uriParams[2])){
            $redirectUrl = $requestedUri.'/1'. ($urlParams ? '?'.http_build_query($urlParams) : '');
            $validateRedirectUrl = $this->validationRedirect($redirectUrl);
            $this->redirect($validateRedirectUrl, 301);
        }

    }

    /**
     * @param $redirectUrl
     * @return string
     */
    private function validationRedirect($redirectUrl)
    {
        $handle=@fopen($this->grav['uri']->base().'/'.$redirectUrl,"r");
        if ($handle){
            fclose($handle);
            return $redirectUrl;
        }
        else{
            return '/';
        }
    }

    /**
     * @param $urlParams
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function validationAudience($urlParams){
        if(isset($urlParams['audience'])){
            $filters =[
                'id' => [
                    'operator' => '_eq',
                    'value' => $urlParams['audience'],
                ],
            ];

            $response = $this->requestItem('zbr_audiences', 0, 1, $filters);
            if(empty($response->toArray()['data'])){
                unset($_GET['audience']);
            }
        }
    }

    /**
     * @param string $route
     * @return bool
     * @throws \JsonException
     */
    private function processWebHooks(string $route) {
        switch ($route) {
            case '/' . $this->config["plugins.directus"]['directus']['hookPrefix'] . '/update-flex-object':
                $this->processFlexObject();
                break;
            case '/' . $this->config["plugins.directus"]['directus']['hookPrefix'] . '/crawl-landingpages':
                $this->crawlLandingpages();
                break;
            case '/' . $this->config["plugins.directus"]['directus']['hookPrefix'] . '/exportCSV':
                $this->exportCSV();
                break;
            case '/' . $this->config["plugins.directus"]['directus']['hookPrefix'] . '/update-flex-objects':
                $this->processFlexObjects();
                break;
        }
        return true;
    }

    /**
     * @throws \JsonException
     */
    private function processFlexObject() {

        $requestBody = json_decode(file_get_contents('php://input'), true);

        if(isset($_REQUEST['debug'])) {
            $this->writeLog($this->buildLogEntry(json_encode( [$requestBody['collection'], $requestBody['item'], $requestBody['action']], JSON_THROW_ON_ERROR), 'request from webhook'));
        }

        $statusCode = 0;

        if(isset($requestBody['collection'])) {

            /** @var FlexCollectionInterface $collection */
            $this->collection = $this->flex->getCollection($requestBody['collection']);

            /** @var FlexDirectoryInterface $directory */
            $this->directory = $this->flex->getDirectory($requestBody['collection']);

            $depth = 2;

            foreach($this->config()['landingpages']['mapping']['collections'] as $key => $value) {
                if($value['tableName'] === $requestBody['collection']) {
                    $depth = $value['depth'];
                }
            }
            try {
                switch ($requestBody['action']) {
                    case "create":
                        $statusCode = $this->createFlexObject($requestBody['collection'], $requestBody['item'], $depth);
                        break;
                    case "update":
                        $statusCode = $this->updateFlexObject($requestBody['collection'], $requestBody['item'], $depth);
                        break;
                    case "delete":
                        $statusCode = $this->deleteFlexObject($requestBody['collection'], $requestBody['item']);
                        break;
                }
            } catch(\Exception $e) {
                $this->writeLog($this->buildLogEntry($e, 'something went wrong'));
            }
        }

        if($statusCode === 200) {
            echo json_encode([
                'status' => '200',
                'message' => 'all done'
            ], JSON_THROW_ON_ERROR);
            Cache::clearCache();
            exit(200);
        }

        echo json_encode([
            'status' => $statusCode,
            'message' => 'something went wrong'
        ], JSON_THROW_ON_ERROR);
        if(isset($_REQUEST['debug'])) {
            $this->writeLog($this->buildLogEntry($statusCode, 'something went wrong'));
        }
        exit($statusCode);
    }

    /**
     * @throws \JsonException
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function processFlexObjects() {

        $this->delTree('user/data/flex-objects');

        $collectionArray = $this->config()['landingpages']['mapping']['collections'];

        foreach ($collectionArray as $key => $value){

            /** @var FlexCollectionInterface $collection */
            $this->collection = $this->flex->getCollection($value['tableName']);

            /** @var FlexDirectoryInterface $directory */
            $this->directory = $this->flex->getDirectory($value['tableName']);

            $response = $this->requestItem($value['tableName'], 0, ($value['depth'] ?? 2));

            foreach ($response->toArray()['data'] as $item){
                $object = $this->collection->get($item['id']);

                if ($object) {
                    $object->update($item);
                    $object->save();
                } else {
                    $objectInstance = new FlexObject($item, $item['id'], $this->directory);
                    $object = $objectInstance->create($item['id']);
                    $this->collection->add($object);
                }
            }
        }
        echo json_encode([
            'status' => 200,
            'message' => 'all done'
        ], JSON_THROW_ON_ERROR);
        Cache::clearCache();
        exit(200);
    }

    /**
     * @param $collection
     * @param $id
     * @param int $depth
     * @return int
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function createFlexObject($collection, $id, int $depth = 2) {
        $response = $this->requestItem($collection, $id, $depth);

        if(isset($_REQUEST['debug'])) {
            $this->writeLog($this->buildLogEntry(json_encode($response->toArray(), JSON_THROW_ON_ERROR), 'create flex object - request to directus | data = response from directus'));
        }

        if($response->getStatusCode() === 200) {
            $data = $response->toArray()['data'];
            $objectInstance = new FlexObject($data, $data['id'], $this->directory);
            $object = $objectInstance->create($data['id']);
            $this->collection->add($object);
        }
        return $response->getStatusCode();
    }

    /**
     * @param $collection
     * @param $ids
     * @param int $depth
     * @return int
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function updateFlexObject($collection, $ids, int $depth = 2) {
        foreach ($ids as $id) {

            try {
                $response = $this->requestItem($collection, $id, $depth);

                if(isset($_REQUEST['debug'])) {
                    $this->writeLog($this->buildLogEntry(json_encode($response->toArray(), JSON_THROW_ON_ERROR), 'update flex object - request to directus | data = response from directus'));
                }
                if($response->getStatusCode() === 200) {
                    $object = $this->collection->get($id);

                    if ($object) {
                        $object->update($response->toArray()['data']);
                        $object->save();
                    } else {
                        $this->createFlexObject($collection, $id);
                    }
                }
            } catch(\Exception $e) {
                if(isset($_REQUEST['debug'])) {
                    $this->writeLog($this->buildLogEntry(json_encode($e, JSON_THROW_ON_ERROR), 'something went wrong'));
                }
            }

        }
        return 200;
    }

    /**
     * @param $collection
     * @param $ids
     * @return int
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function deleteFlexObject($collection, $ids) {
        foreach ($ids as $id) {
            $object = $this->collection->get($id);
            if($object) {
                $object->delete();
            }
        }
        return 200;
    }

    /**
     * @param $collection
     * @param $id
     * @param int $depth
     * @return \Symfony\Contracts\HttpClient\ResponseInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function requestItem($collection, $id = 0, $depth = 2, $filters = []) {

        $requestUrl = $this->directusUtil->generateRequestUrl($collection, $id, $depth, $filters);
        return $this->directusUtil->get($requestUrl);
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function crawlLandingpages(){

        $this->delTree($this->config()['landingpages']['entrypoint']);

        $filters =[
            'id_zbr_keywords' => [
                'operator' => '_nnull',
                'value' => 'true',
            ],
            'id_zbr_landingpages' => [
                'operator' => '_nnull',
                'value' => 'true',
            ],
        ];

        $response = $this->requestItem($this->config()['landingpages']['entrytable'], 0, 2, $filters);

        if($response->getStatusCode() === 200){
            $i = 0;
            foreach ($response->toArray()['data'] as $landingpage){
                if(isset($landingpage['id_zbr_landingpages']) && $landingpage[$this->config()['landingpages']['mapping']['keyword']]){
                    $this->createFile(
                        $this->setFileHeaders($landingpage),
                        $landingpage[$this->config()['landingpages']['mapping']['keyword']][$this->config()['landingpages']['mapping']['keywordHash']],
                        $landingpage['id_zbr_landingpages']['id_zbr_audiences']
                    );

                    $i++;
                }
            }
            $message = $i.' elements created';
        }
        else{
            $message = 'something went wrong';
        }

        echo json_encode([
            'status' => $response->getStatusCode(),
            'message' => $message
        ], JSON_THROW_ON_ERROR);
        Cache::clearCache();
        exit($response->getStatusCode());
    }

    /**
     * @param string $frontMatter
     * @param $hash
     * @param $audience
     */
    private function createFile(string $frontMatter, $hash, $audience) {

        $filename = 'landingpage.md';

        if(!is_dir($this->config()['landingpages']['entrypoint'].'/'.$hash)){
            mkdir($this->config()['landingpages']['entrypoint'].'/'.$hash);
        }

        if(!is_dir($this->config()['landingpages']['entrypoint'].'/'.$hash.'/'.$audience)){
            mkdir($this->config()['landingpages']['entrypoint'].'/'.$hash.'/'.$audience);
        }

        $fp = fopen($this->config()['landingpages']['entrypoint'].'/'.$hash.'/'.$audience . '/' . $filename, 'w');

        fwrite($fp, $frontMatter);
        fclose($fp);
    }

    /**
     * @param array $dataSet
     * @return string
     */
    private function setFileHeaders(array $dataSet) {


        $mappingCollections = $this->config()['landingpages']['mapping']['collections'];


        if(isset($dataSet['id_zbr_landingpages']) && isset($dataSet['id_zbr_keywords'])){
            return '---' . "\n" .
                'title: ' . "'" . htmlentities($dataSet['id_zbr_landingpages']['zbr_headline'], ENT_QUOTES) . "'\n" .
                'dataset:' . "\n" .
                '    '.$mappingCollections['id_zbr_keywords']['tableName'].': ' . $dataSet['id_zbr_keywords']['id'] ."\n" .
                '    '.$mappingCollections['id_zbr_landingpages']['tableName'].': ' . $dataSet['id_zbr_landingpages']['id'] ."\n" .
                '    '.$mappingCollections['id_zbr_audience']['tableName'].': ' . $dataSet['id_zbr_landingpages']['id_zbr_audiences'] ."\n" .
                '---';
        }
    }

    /**
     * @param $url
     * @param int $statusCode
     */
    private function redirect( $url, int $statusCode = 303)
    {
        header('Location: ' . $url, true, $statusCode);
        die();
    }

    /**
     * @throws \JsonException
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function exportCSV(){

        if(isset($_GET['token'])){
            $this->directusUtil->setToken($_GET['token']);

            $exportSettings = $this->requestItem($this->config()['landingpages']['confTable']);
            $array = [];

            $settings = array_filter($exportSettings->toArray()['data'], function ($match){
                $settingName = $_GET[ 'conf' ] ?? $this->config()[ 'landingpages' ][ 'entryConf' ];

                if($match['key'] === $settingName){
                    return $match;
                }
            });

            if(!empty($settings)){
                $settings = array_values($settings);
            }
            else{
                echo json_encode([
                    'status' => 403,
                    'message' => 'Bad Request'
                ], JSON_THROW_ON_ERROR);
                exit(403);
            }

            $settings[0]['value']['entrytable'] ? $entrytable = $settings[0]['value']['entrytable'] : $entrytable = $this->config()['landingpages']['entrytable'];
            unset($settings[0]['value']['entrytable']);

            $filters =[
                'id_zbr_keywords' => [
                    'operator' => '_nnull',
                    'value' => 'true',
                ],
                'id_zbr_landingpages' => [
                    'operator' => '_nnull',
                    'value' => 'true',
                ],
            ];

            $response = $this->requestItem($entrytable, 0, 3);
            $filename = ($_GET['conf'] ?? $this->config()['landingpages']['exportFilename']).'_'.date('Y-m-d_H:i:s').'.csv';
            if($response->getStatusCode() === 200) {
                $formatter = new CsvFormatter(['file_extension' => '.csv', 'delimiter' => ";"]);
                $file = new CsvFile($this->config()['landingpages']['exportPath'].$filename, $formatter);

                foreach ($response->toArray()['data'] as $item){

                    foreach ($settings[0]['value'] as $key => $value){
                        $params = explode('.', $value);
                        if(isset($params[2])){
                            if(isset($item[$params[0]]) && isset($params[2]) && isset($item[$params[0]][$params[1]][$params[2]])){
                                $array[$item['id']][$key]  = $item[$params[0]][$params[1]][$params[2]];
                            }
                            else{
                                $array[$item['id']][$key]  = '-';
                            }
                        }
                        elseif (isset($item[$params[0]]) && isset($params[1]) && isset($item[$params[0]][$params[1]])){

                            $array[$item['id']][$key]  = $item[$params[0]][$params[1]];
                        }
                        elseif (isset($item[$params[0]])){
                            $array[$item['id']][$key]  = $item[$params[0]];
                        }
                        else{
                            $array[$item['id']][$key]  = '-';
                        }
                    }
                }
                $file->save(array_values($array));
            }
            header( 'Content-type: application/csv' );
            header( 'Content-Disposition: attachment; filename="'.$filename.'"' );
            readfile( $this->config()['landingpages']['exportPath'].$filename );

            exit(200);
        }
        else{
            echo json_encode([
                'status' => 403,
                'message' => 'Bad Request'
            ], JSON_THROW_ON_ERROR);

            exit(403);
        }
    }

    private function delTree($dir){
        $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
            $todo( $fileinfo->getRealPath() );
        }
    }

    private function buildLogEntry($data, $action) {
        $logText =  '######################################################################' . "\n" .
                    'Date & Time: ' . date("Y/m/d H:i:s", time()) . "\n" .
                    'Action: ' . $action . "\n" .
                    'Data:' . "\n" .
                    '----------------------------------------------------------------------' . "\n" .
                    $data . "\n" .
                    '----------------------------------------------------------------------' . "\n";
        return $logText;
    }

    private function writeLog($data) {
        file_put_contents('logs/webhook_log_'.date("j.n.Y-H").'.log', $data, FILE_APPEND);
    }
}
