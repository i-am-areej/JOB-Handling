<?php
namespace App\Traits;
trait ValidationTrait
{
    public function validateJobData($data)
    {
        if (!isset($data['from_language_id'])) {
            return ['status' => 'fail', 'message' => 'Language is required', 'field_name' => 'from_language_id'];
        }
        if ($data['immediate'] === 'no') {
            if (empty($data['due_date']) || empty($data['due_time'])) {
                return ['status' => 'fail', 'message' => 'Due date and time are required', 'field_name' => 'due_date_due_time'];
            }
        }
        return ['status' => 'success'];
    }
}