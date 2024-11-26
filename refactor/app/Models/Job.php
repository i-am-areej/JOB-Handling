<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    public function scopeFilterFeedback(Builder $query, $feedback)
    {
        if ($feedback != 'false') {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
        }
        return $query;
    }

    public function scopeFilterById(Builder $query, $id)
    {
        if (!empty($id)) {
            is_array($id) ? $query->whereIn('id', $id) : $query->where('id', $id);
        }
        return $query;
    }

    public function scopeFilterByLang(Builder $query, $langs)
    {
        if (!empty($langs)) {
            $query->whereIn('from_language_id', $langs);
        }
        return $query;
    }

    public function scopeFilterByStatus(Builder $query, $status)
    {
        if (!empty($status)) {
            $query->whereIn('status', $status);
        }
        return $query;
    }

    public function scopeFilterByExpiredAt(Builder $query, $expired_at)
    {
        if (!empty($expired_at)) {
            $query->where('expired_at', '>=', $expired_at);
        }
        return $query;
    }

    public function scopeFilterByWillExpireAt(Builder $query, $will_expire_at)
    {
        if (!empty($will_expire_at)) {
            $query->where('will_expire_at', '>=', $will_expire_at);
        }
        return $query;
    }

    public function scopeFilterByCustomerEmail(Builder $query, $emails)
    {
        if (!empty($emails) && count($emails)) {
            $users = DB::table('users')->whereIn('email', $emails)->pluck('id');
            $query->whereIn('user_id', $users);
        }
        return $query;
    }

    public function scopeFilterByTranslatorEmail(Builder $query, $emails)
    {
        if (!empty($emails) && count($emails)) {
            $users = DB::table('users')->whereIn('email', $emails)->pluck('id');
            $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $users)->pluck('job_id');
            $query->whereIn('id', $jobIds);
        }
        return $query;
    }
    public function scopeFilterBySessionTime($query)
    {
        return $query->whereRaw("TIME_TO_SEC(session_time) >= duration * 2");
    }
    public function scopeFilterByLanguage($query, $languageIds)
    {
        if (!empty($languageIds)) {
            return $query->whereIn('from_language_id', $languageIds);
        }
        return $query;
    }
    public function scopeFilterByUserEmail($query, $email)
    {
        if (!empty($email)) {
            $user = User::where('email', $email)->first();
            if ($user) {
                return $query->where('user_id', $user->id);
            }
        }
        return $query;
    }
    public function scopeFilterByTimeType(Builder $query, $timeType, $from, $to)
    {
        if ($timeType == "created") {
            if (!empty($from)) {
                $query->where('created_at', '>=', $from);
            }
            if (!empty($to)) {
                $query->where('created_at', '<=', $to . " 23:59:00");
            }
            $query->orderBy('created_at', 'desc');
        } elseif ($timeType == "due") {
            if (!empty($from)) {
                $query->where('due', '>=', $from);
            }
            if (!empty($to)) {
                $query->where('due', '<=', $to . " 23:59:00");
            }
            $query->orderBy('due', 'desc');
        }
        return $query;
    }

    public function scopeFilterByJobType(Builder $query, $jobType)
    {
        if (!empty($jobType)) {
            $query->whereIn('job_type', $jobType);
        }
        return $query;
    }

    public function scopeFilterByPhysical(Builder $query, $physical)
    {
        if (isset($physical)) {
            $query->where('customer_physical_type', $physical)->where('ignore_physical', 0);
        }
        return $query;
    }

    public function scopeFilterByPhone(Builder $query, $phone, $physical = null)
    {
        if (isset($phone)) {
            $query->where('customer_phone_type', $phone);
            if (isset($physical)) {
                $query->where('ignore_physical_phone', 0);
            }
        }
        return $query;
    }

    public function scopeFilterByFlagged(Builder $query, $flagged)
    {
        if (isset($flagged)) {
            $query->where('flagged', $flagged)->where('ignore_flagged', 0);
        }
        return $query;
    }

    public function scopeFilterByDistance(Builder $query, $distance)
    {
        if ($distance == 'empty') {
            $query->whereDoesntHave('distance');
        }
        return $query;
    }

    public function scopeFilterBySalary(Builder $query, $salary)
    {
        if ($salary == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }
        return $query;
    }

    public function scopeFilterByConsumerType(Builder $query, $consumerType)
    {
        if (!empty($consumerType)) {
            $query->whereHas('user.userMeta', function ($q) use ($consumerType) {
                $q->where('consumer_type', $consumerType);
            });
        }
        return $query;
    }

    public function scopeFilterByBookingType(Builder $query, $bookingType)
    {
        if (!empty($bookingType)) {
            if ($bookingType == 'physical') {
                $query->where('customer_physical_type', 'yes');
            } elseif ($bookingType == 'phone') {
                $query->where('customer_phone_type', 'yes');
            }
        }
        return $query;
    }

    public function scopeWithAllRelationships(Builder $query)
    {
        return $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    }
}
