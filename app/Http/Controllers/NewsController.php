<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SimplePie;

use Log;
use App\Http\Requests;
use Illuminate\Support\Facades\Response;
use League\OAuth2\Client\Provider\GenericProvider;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use App\Extensions\Helper;
use App\Extensions\BingHelper;
use App\Extensions\SpotifyHelper;

class NewsController extends Controller
{

    public function __construct(Helper $helper, SpotifyHelper $spotify){
        $this->helper = $helper;
        $this->spotify = $spotify;
    }


    public function webhook(Request $request){
        //Getting the POST request from API.AI and decoding it
        $results = json_decode($request->getContent(), true);
        
        $answer = $this->answer($results);

        $context = '';

        if($answer['music'] === null){
            //if necessary, we can truncate the length of the body here with the function truncate()
            //use as follow: $body = $this->truncate($answer['news']['body'], 100)
            // 100 is the length you can put what ever, it is smart so it will only cut after full words
            $body = $this->helper->truncate($answer['news']['body'], 300);
            //$response = $answer['speech']."\n\n".$answer['news']['title']."\n\n".$body."\n\nRead more: ".$answer['news']['link'];
            $response = $answer['news']['title']."\n\n".$body."\n\nRead more: ".$answer['news']['link'];
            $displayText = null;
            $source = $answer['news']['source'];
            if($answer['intent'] == "more-info") { 
                $context = [
                    'name' => 'next-news', 
                    'lifespan' => 5, 
                    'parameters' => [
                        'offset-news' => $answer['offset-news'],
                        'adjective' => $answer['adjective'],
                        'subject' => $answer['subject']
                    ]
                ];
            }

            if($answer['news']['emotion'] !== null){

                if($answer['news']['language'] == 'english'){
                    $watsonSpeech = "According to Watson the main emotion expressed in the article is: ";
                } elseif($answer['news']['language'] == 'german'){
                    $watsonSpeech = "Laut Watson Hauptemotion in diesem Artikel ist: ";
                }

                $response = $answer['speech']
                    ."\n\n ".$watsonSpeech
                    .$answer['news']['emoticon']
                    ." ( ".$answer['news']['emotion']." )\n\n  "
                    .$answer['news']['title']."\n\n".$body."\n\nRead more: "
                    .$answer['news']['link'];

                $displayText = $answer['speech'].". ".$watsonSpeech.$answer['news']['emotion'];
            }
        } else {
            $response = $answer['music']['title']
                ."\n\n(music)\n\n"
                .$answer['music']['url']
                ."\n\nlisten to the full song here: "
                .$answer['music']['full'];
            
            $displayText = "Title: ".$answer['music']['title'];
            $source = "Spotify";

            if($answer['intent'] == "next song") {    
                $context = [
                    'name' => 'next-song', 
                    'lifespan' => 5, 
                    'parameters' => [
                        'offset-song' => $answer['offset-song']
                        ]
                    ];
            }
        }

        if(isset($answer['news']['title']) || $answer['music']){
                $speech = $response;
                $text = $displayText;
        } else {
                $speech = $answer['speech'];
                $text = $answer['speech'];
            }

       // Log::debug("to send >>>>>>>>>>>>> ".$context);

        //this is a valid response for API.AI 
        return Response::json([
                    'speech'   => $speech,
                    'displayText' => $text,
                    'data' => ['newsAgent' => $answer],
                    'contextOut' => [$context],
                    'source' => $source
            ], 200);

    }


    public function apiAi(Request $request){

        $results = $this->sendRequest($request);
        if(isset($results['result']['fulfillment']['data'])){
            $result = $results['result']['fulfillment']['data']['newsAgent'];
        } else {
            $result = $results['result']['fulfillment'];
            $result['action'] = isset($results['result']['action']) ? $results['result']['action'] : null;
        }

        //Here we format the response for the JS on the frontend
        return Response::json([
                'news'  => isset($result['news']) ? $result['news'] : null,
                'music' => isset($result['music']) ? $result['music'] : null,
                'speech'   => $result['speech'],
                'action' => $result['action'],
                'subject' => isset($result['subject']) ? $result['subject'] : null,
                'intent' => isset($result['intent']) ? $result['intent'] : null,
                'adjective' => isset($result['adjective']) ? $result['adjective'] : null
            ], 200);
    }

    /**
    * Send the Request to API.AI
    * This method is used by the webapp. Api.AI calls the webhook above
    * The response is what API.AI got from the webhook.
    * @param object $request
    * @return array
    */
    public function sendRequest($request){
        $apiai_key = env('API_AI_ACCESS_TOKEN');
        $apiai_subscription_key = env('API_AI_DEV_TOKEN');
        
        $query = $request->input('query');
        
        $client = new Client();

        $send = ['headers' => [
                    'Content-Type' => 'application/json;charset=utf-8', 
                    'Authorization' => 'Bearer '.$apiai_key
                    ],
                'body' => json_encode([                
                    'query' => array($query), 
                    'lang' => 'en',
                    'sessionId' => '1234567890'
                    ])
                ];  

        $response = $client->post('https://api.api.ai/v1/query?v=20150910', $send);

        return json_decode($response->getBody(),true);
    }


    /**
    * Parse the results received from API.AI and parse it with some logic to
    * get some news or music to display
    * @param array $results
    * @return array
    */
    public function answer($results){
        //API.AI Fulfillment
        $speech = isset($results['result']['fulfillment']['speech']) ? $results['result']['fulfillment']['speech'] : '';
        $newsSource = isset($results['result']['fulfillment']['source']) ? $results['result']['fulfillment']['source'] : '';
        //$displayText = isset($results['result']['fulfillment']['displayText']) ? $results['result']['fulfillment']['displayText'] : '';
        //API.AI Result 
        $query = isset($results['result']['resolvedQuery']) ? $results['result']['resolvedQuery'] : false;
        $action = isset($results['result']['action']) ? $results['result']['action'] : false;
        $intent = isset($results['result']['metadata']['intentName']) ? $results['result']['metadata']['intentName'] : false;;
        $apiAiSource = isset($results['result']['source']) ? $results['result']['source'] : false;
        //API.AI Result par ams
        $subject = isset($results['result']['parameters']['subject']) ? $results['result']['parameters']['subject'] : false;
        $music = isset($results['result']['parameters']['music-subject']) ? $results['result']['parameters']['music-subject'] : false;
        $adjective = isset($results['result']['parameters']['adjective']) ? $results['result']['parameters']['adjective'] : false;
        $news = isset($results['result']['parameters']['news']) ? $results['result']['parameters']['news'] : false;
        $sessionId = isset($results['sessionId']) ? $results['sessionId'] : "agent-" . base64_encode(random_bytes(10));
        //API.AI Fulfillment data (News agent data sent back...)
        //$data = isset($results['result']['fulfillment']['data']['newsAgent']) ? $results['result']['fulfillment']['data']['newsAgent'] : null;
        //$title = isset($data['title']) ? $data['title'] : null;
        //$image = isset($data['image']) ? $data['image'] : null;
        //$webSource = isset($data['source']) ? $data['source'] : null;
        //$link = isset($data['link']) ? $data['link'] : null;
        //$body = isset($data['body']) ? $data['body'] : null;
        //$language = isset($data['languange']) ? $data['languange'] : null;
        //$emotion = isset($data['emotion']) ? $data['emotion'] : null;
        //$emoticon = isset($data['emoticon']) ? $data['emoticon'] : null;
        $indexSong = $this->helper->getIndex('next-song', $results['result']['contexts']);
        $indexNews = $this->helper->getIndex('next-news', $results['result']['contexts']);
        $offsetSong = isset($results['result']['contexts'][$indexSong]['parameters']['offset-song']) ? $results['result']['contexts'][$indexSong]['parameters']['offset-song'] : 0;
        if(empty($offsetSong)){
            $offsetSong = 0;
        } 
        $offsetNews = isset($results['result']['contexts'][$indexNews]['parameters']['offset-news']) ? $results['result']['contexts'][$indexNews]['parameters']['offset-news'] : 0;
        if(empty($offsetNews)){
            $offsetNews = 0;
        } 

        //Response defaults
        $answer['adjective'] = $adjective;
        $answer['subject'] = $subject;
        $answer['intent'] = $intent;
        $answer['action'] = $action;
        //$answer['offset.original'] = $offset;
        $answer['news'] = null;
        $answer['music'] = null;  
        $answer['speech'] = $speech;
        $answer['sessionId'] = $sessionId;

        $resolvedQuery = $query;

        //small check in case nothing is returned
        if(!$action && $speech == '' && !$subject){
            $answer['speech'] = "Sorry, ".$resolvedQuery." did not return any result";
            $answer['news'] = 'Nothing is happening right now. Check later!';
        } 
  
        if($action == "show.news"){
        //if local -> query needs to be site:blick.ch
                        $category = '';
                        $query = $subject;
                        if(empty($subject)){
                            $query = $adjective;
                        }

                        if(empty($subject) && empty($adjective)){
                            $subject = $resolvedQuery;
                        }

                        if($intent == "more-info") {
                            ++$offsetNews;
                            $answer['offset-news'] = $offsetNews;
                        }
                        $market = 'en-US';
                        
                        if($adjective != 'local'){
                            $root = '(site:cnn.com/world OR site:usatoday.com/news/world OR site:washingtonpost.com/world)';
                            $cat = '+';
                            $query = $root.$cat.$subject;
                        }

                        if($subject == 'football' || $subject == 'soccer' || $subject == 'tennis' || $subject == 'hockey'){
                            $root = '(site:cnn.com/sport OR site:usatoday.com/sports OR site:washingtonpost.com/sports)';
                            $cat = '+';
                            $query = $subject;
                        }


                        //let's consider for now that local news come from Blick.ch
            
                        if($adjective == 'local' || $adjective == 'swiss' || $adjective == 'Swiss' || $subject == "Switzerland" || $subject == "switzerland"){
                            $market = 'de-CH';
                            $root = 'site:blick.ch';
                            $cat = '+';
                            //here we redirect all this news to the swiss cat of blick
                            $cat = '/news/schweiz';

                            if($subject == 'football' ||
                                $subject == 'soccer' ||
                                $subject == 'tennis' ||
                                $subject == 'hockey' ||
                                $subject == 'sport'){
                                $cat = '/sport+';
                            }  else {
                                $subject = '';
                            }
                            $category = false;
                            $query = $root.$cat.$subject;
                        } 


                        //dd("Cat:" .$category . " Query: " . $query);
                        $bing = new BingHelper();
                        $response = $bing->getNews($query, $offsetNews, $market, $category);
                        $answer['news'] = $response['item'];
                        //Adding speech for the webapp. $displayText is used because $speech "enriched"
                        //to display more info (emoticons, urls, etc) in skype and other bots as far 
                        //as API.AI uses this key to answer the user.
                        $answer['speech'] = $speech;
        }

        if($action == "play.music"){
                if($intent == "next song") {
                        ++$offsetSong;
                        $answer['offset-song'] = $offsetSong;    
                }

                if(!empty($subject) && !empty($adjective)){
                    $song = $this->spotify->getSong($adjective . " " . $subject, $offsetSong);
                } elseif (!empty($adjective && empty($subject))) {
                    $song = $this->spotify->getSong($adjective, $offsetSong);
                } else {
                    $song = $this->spotify->getSong($subject, $offsetSong);
                }

                if($song != null){ 
                    $answer['music'] = $song;
                } else {
                    $answer['music'] = $this->spotify->getSong("Opera", $offsetSong);
                }   

        }

        return $answer ;
    }
}
