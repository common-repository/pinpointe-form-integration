<?php
/**
 * @package pinpointe-signup-form
 * @author Andrew
 */

if (!class_exists('PinpointeService')) {
    class PinpointeService
    {
        /**
         * @var string
         */
        private $apiUrl;

        /**
         * Pinpointe username
         * @var string
         */
        private $username;

        /**
         * Pinpointe user token
         * @var string
         */
        private $userToken;
        
        private $default_options = [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
        ];

        /**
         * Create a new PinpointeService object
         */
        public function __construct()
        {
            if (!function_exists('wp_remote_post')) {
                throw new \Exception('WP Remote Post is not available and is required.');
            }

            if (!class_exists('SimpleXMLElement')) {
                throw new \Exception('SimpleXML is not installed and is required.');
            }
        }

        /**
         * Set the API URL
         * @param $url
         */
        public function setApiUrl($url) {
            $this->apiUrl = $url;
        }

        /**
         * Set the credentials to use when making API calls
         * @param $username
         * @param $token
         */
        public function setCredentials($username, $token)
        {
            $this->username = $username;
            $this->userToken = $token;
        }

        /**
         * Call the Pinpointe API with the specified request type, method, and data
         * @param $type
         * @param $method
         * @param array $data
         */
        private function call($type, $method, $data = array())
        {
            if (!$this->username || !$this->userToken) {
                throw new \Exception('You must set API credentials before calling the Pinpointe API.');
            }

            $request = array(
                'username' => $this->username,
                'usertoken' => $this->userToken,
                'requesttype' => $type,
                'requestmethod' => $method,
                'details' => $data
            );

            $is_json = true;
            if(strpos($this->apiUrl, 'json') === false){
                $is_json = false;
                $xml = $this->arrayToXml($request, '<xmlrequest />');
                // We have to use DOMDocument now to prevent self closing XML tags since the
                // Pinpointe API doesn't accept them.
                $dom_sxe = dom_import_simplexml($xml);

                $dom_output = new DOMDocument('1.0');
                $dom_output->formatOutput = true;
                $dom_sxe = $dom_output->importNode($dom_sxe, true);
                $dom_sxe = $dom_output->appendChild($dom_sxe);

                $post_data = $dom_output->saveXML($dom_output, LIBXML_NOEMPTYTAG);

                $file = dirname(__FILE__, 2) . DIRECTORY_SEPARATOR. "test.xml";
                $dom_output->save($file);
                
                $args = array_merge(
                    $this->default_options,
                    [
                        'headers' => [
                            'Content-Type' => 'application/xml',
                        ],
                        'body' => $post_data,
                    ]
                );
            } else {
                $post_data = wp_json_encode($request);
                $args = array_merge(
                    $this->default_options,
                    [
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                        'body' => $post_data,
                    ]
                );
            }
            
            $response = wp_remote_post($this->apiUrl, $args);

            $responseBody = wp_remote_retrieve_body($response);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
             
                if (is_array($response)) {

                    $error_data = !$is_json ? json_encode($responseBody) : $responseBody;

                    $error_message = reset(json_decode($error_data, true)['errormessage']);
                } else {
                    $error_message = $response->get_error_message();
                }

                throw new \Exception(
                    sprintf('API call of type %s failed: %s', $type , $error_message)
                );
            }

            $response = $is_json ? json_decode($responseBody) : simplexml_load_string($responseBody);

            if ($response->status === 'SUCCESS') {
				if (isset($data['id']) && $data['id'] == '-999') {
                    return true;
                }
				return $this->xmlToArray($response->data);
            }

            throw new \Exception(sprintf('There was a problem: %s', $responseBody));
        }

        /**
         * Build an XML tree from the specified array. Only supports XML values-- attributes are not
         * supported.
         * @param $arr
         * @param null $root
         * @return null|SimpleXMLElement
         */
        private function arrayToXml($arr, $root = null)
        {
            if (is_string($root)) {
                $root = new \SimpleXMLElement($root);
            }

            foreach ($arr as $key => $value) {
                if (is_array($value)) {
                    if ($key != 'value') {
                        if ($key == 'tags') {
                            foreach ($value as $tag) {
                                $child = $root->addChild('tag', $tag);
                            }
                        } else {
                            $child = $root->addChild($key);
                            $this->arrayToXml($value, $child);
                        }
                    } else {
                        foreach ($value as $k => $v) {
                            $root->addChild($key, $v);
                        }
                    }
                } elseif (is_a($value, 'SimpleXMLElement')) {
                    $r = $root->addChild($value->getName());

                    foreach($value->children() as $child) {
                        $r->addChild($child->getName(), $child);
                    }
                } else {
                    $root->addChild($key, $value);
                }
            }

            return $root;
        }

        /**
         * Convert a SimpleXML object to a plain associative array. Does not support attributes
         * @param SimpleXMLElement|array $xml
         * @param array $out
         * @return array
         */
        private function xmlToArray($xml)
        {
            return json_decode(wp_json_encode($xml), TRUE);
        }

        /**
         * Get the lists
         * @param array $filters
         * @param int $start
         * @param int $limit
         */
        public function lists_get_list($start = 0, $limit = 25)
        {
            $lists = $this->call('lists', 'GetLists');
            if(isset($lists['item'])) {
                $lists = $lists['item'];
            }

            if(!is_array($lists) || $this->isAssociativeArray($lists)) {
                $lists = [$lists];
            }

            return array_slice($lists, min($start, count($lists) - 1), max($limit, count($lists) - $limit));
        }

        /**
         * Get the tags
         * @param array $filters
         * @param int $start
         * @param int $limit
         */
        public function tags_get_tag($start = 0, $limit = 25)
        {
            $tags = $this->call('tags', 'GetTags');
            if(isset($tags['item'])) {
                $tags = $tags['item'];
            }

            if(!is_array($tags) || $this->isAssociativeArray($tags)) {
                $tags = [$tags];
            }

            return array_slice($tags, min($start, count($tags) - 1), max($limit, count($tags) - $limit));
        }

        /**
         * Get merge vars
         *
         * @access public
         * @param string $id
         * @return mixed
         */
        public function lists_merge_vars($id)
        {
            if(!is_array($id)) {
                $id = [$id];
            }

            $allMergeVars = [];

            foreach($id as $i) {
				$response = $this->call('lists', 'GetCustomFields', ['listids' => $i]);
				
				// for xml item exists, for json item doesnot exist
                $mergeVarForList = isset($response['item']) ? $response['item']: $response;

                $mergeVars = [
                    'email' => [
                        'name' => 'Email',
                        'req' => true,
                        'field_type' => 'email'
                    ]
                ];

                foreach($mergeVarForList as $mergeVar) {
                    $settings = $mergeVar['fieldsettings'];

                    // Check if $settings is a serialized string, and unserialize if needed
                    if (is_string($settings) && ($unserialized = $this->mb_unserialize($settings)) !== false) {
                        $settings = $unserialized;
                    }

                    $mergeVars[$mergeVar['fieldid']] = [
                        'name' => $mergeVar['name'],
                        'req' => !!$mergeVar['required'],
                        'field_type' => $mergeVar['fieldtype']
                    ];

                    if($mergeVar['fieldtype'] == 'dropdown' || $mergeVar['fieldtype'] == 'checkbox' || $mergeVar['fieldtype'] == 'radiobutton') {
                        $choices = [];
                        if (isset($settings['fieldsettings']) && is_array($settings['fieldsettings'])) {
                            foreach ($settings['fieldsettings'] as $key => $value) {
                                $choices[$key] = $value;
                            }
                        }

                        $mergeVars[$mergeVar['fieldid']]['choices'] = $choices;
                    }
                }

                $allMergeVars[$i] = $mergeVars;
            }

            return $allMergeVars;
        }

        /**
         * Mulit-byte Unserialize
         *
         * UTF-8 will screw up a serialized string
         *
         * @access private
         * @param string
         * @return string
         */
        function mb_unserialize($string) {
            if (is_array($string)) {
                return $string;
            }
            if (@unserialize($string) === false) {
                return $string;
            }

            $string = preg_replace_callback(
                '/s:(\d+):"(.*?)";/',
                function ($matches) {
                    return 's:' . strlen($matches[2]) . ':"' . $matches[2] . '";';
                },
                $string
            );

            return unserialize($string);
        }

        /**
         * Subscribe a user to the specified list ID
         * @param $id
         * @param $email
         * @param array $merge_vars
         * @param string $email_type
         * @param bool $update_existing
         * @throws Exception
         */
        public function lists_subscribe($id, $email, $merge_vars = array(), $email_type = 'html',
                                        $add_to_autoresponders = false, $update_existing = false, $tag_ids = [], $send_confirmation = false, $custom_data = []) {
            if(!is_string($email)) {
                $email = $email['email'];
            }

            $data = array(
                'emailaddress' => $email,
                'mailinglist' => $id,
                'format' => $email_type,
                'customfields' => []
            );

            if (!empty($tag_ids))
                $data['tags'] = $tag_ids;

            if ($send_confirmation) {
                $data['confirmed'] = 'no';
                $data['send_confirmation'] = 'true';
            }

            if(!empty($custom_data['html_confirmation_email'])){
                $data['html_confirmation_email'] = $custom_data['html_confirmation_email'];
            }

            if($add_to_autoresponders) {
                $data['add_to_autoresponders'] = 'true';
            }

            $list_merge_vars = $this->lists_merge_vars($id)[$id];
            foreach($merge_vars as $key => $val) {
                if($list_merge_vars[$key]['field_type'] == 'dropdown') {
                    $choices = $list_merge_vars[$key]['choices'];

                    $val = array_keys($choices)[array_search($val, array_values($choices))];
                }

                // if ($list_merge_vars[$key]['field_type'] == 'checkbox') {
                //     $val = '[' . implode(',', $val) . ']';
                // }

                $data['customfields'][] = $this->arrayToXml([
                    'fieldid' => $key,
                    'value' => $val
                ], '<item />');
            }

            try {
                $this->call('subscribers', 'AddSubscriberToList', $data);
            } catch(\Exception $e) {
                // The subscriber is already subscribed.
                if($update_existing) {
                    $this->call('subscribers', 'UpdateSubscriber', $data);
                } else {
                    // We want to display the error
                    throw $e;
                }
            }
        }

        /**
         * Unsubscribe a user from the specified list
         * @param $id
         * @param $email
         */
        public function lists_unsubscribe($id, $email) {
            $data = array(
                'emailaddress' => $email,
                'list' => $id
            );

            $this->call('subscribers', 'DeleteSubscriber', $data);
        }

        /**
         * Ping Pinpointe to ensure the API key works
         *
         * @access public
         * @return mixed
         */
        public function helper_ping()
        {
            // This is a hack so call() will return a boolean if the API call was 
            // successfully authenticated. We cannot use the Validate Token API because it
            // is enterprise-only.
            $data = $arrayName = array( 'id' => '-999' );
            $result = $this->call('lists', 'GetLists', $data);
            return $result;
        }

        /**
         * Get the account details
         */
        public function helper_account_details() {
            return [
                'username' => $this->username
            ];
        }

        private function isAssociativeArray($arr) {
            return array_keys($arr) !== range(0, count($arr) - 1);
        }
    }
}
