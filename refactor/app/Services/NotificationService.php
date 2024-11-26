<?php
namespace App\Services;

use App\Traits\LoggingTrait;
use App\Models\Job;

class NotificationService
{
    use LoggingTrait;

    /**
     * Function to send OneSignal Push Notifications with User-Tags
     * 
     * @param array $users
     * @param int $job_id
     * @param array $data
     * @param array $msg_text
     * @param bool $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        // Initialize logger
        $this->initializeLogger('push_logger');

        $this->logInfo("Push notification initiated for job {$job_id}", compact('users', 'data', 'msg_text', 'is_need_delay'));

        // Fetch OneSignal configurations
        $env = env('APP_ENV') == 'prod' ? 'prod' : 'dev';
        $onesignalAppID = config("app.{$env}OnesignalAppID");
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config("app.{$env}OnesignalApiKey"));

        // Prepare user tags
        $user_tags = $this->getUserTagsStringFromArray($users);

        // Notification-specific configurations
        $data['job_id'] = $job_id;
        $ios_sound = $data['notification_type'] == 'suitable_job' && $data['immediate'] == 'no' 
            ? 'normal_booking.mp3' 
            : 'emergency_booking.mp3';
        $android_sound = str_replace('.mp3', '', $ios_sound);

        // Prepare payload
        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound,
        ];

        if ($is_need_delay) {
            $fields['send_after'] = \DateTimeHelper::getNextBusinessTimeString();
        }

        $fieldsJson = json_encode($fields);

        // Send request
        $response = $this->sendCurlRequest("https://onesignal.com/api/v1/notifications", $fieldsJson, $onesignalRestAuthKey);

        $this->logInfo("Push notification sent for job {$job_id}", ['response' => $response]);
    }

    /**
     * Send a cURL request
     * 
     * @param string $url
     * @param string $payload
     * @param string $authKey
     * @return string
     */
    private function sendCurlRequest($url, $payload, $authKey)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $authKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * Placeholder function for fetching user tags as a JSON string
     * 
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        // Logic to generate user tags
        return json_encode([
            ['key' => 'user_id', 'relation' => '=', 'value' => implode(',', $users)]
        ]);
    }
 
  
}
