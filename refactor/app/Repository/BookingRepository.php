<?php

namespace App\Repositories;

use App\Models\Job;
use App\Models\User;
use App\Traits\LoggingTrait;
use App\Traits\ValidationTrait;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Events\JobWasCreated;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use App\Services\NotificationService;

class BookingRepository extends BaseRepository

{
    use LoggingTrait, ValidationTrait;

    protected $mailer;
    protected $model;
    protected $logger;
    protected $notificationService;

    public function __construct(MailerInterface $mailer, Job $model, NotificationService $notificationService)
    {
        $this->mailer = $mailer;
        $this->notificationService = $notificationService;
        $this->initializeLogger('booking_logger');
        parent::__construct($model);
    }

    /**
     * Get jobs for a user.
     *
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobs($cuser);
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobs($cuser);
                $usertype = 'translator';
            }

            if ($jobs) {
                foreach ($jobs as $job) {
                    if ($job->immediate == 'yes') {
                        $emergencyJobs[] = $job;
                    } else {
                        $normalJobs[] = $job;
                    }
                }

                $normalJobs = collect($normalJobs)->each(function ($job) use ($user_id) {
                    $job['usercheck'] = Job::checkParticularJob($user_id, $job);
                })->sortBy('due')->all();
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
        ];
    }

    private function getCustomerJobs($cuser)
    {
        return $cuser->jobs()
            ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
            ->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')
            ->get();
    }

    private function getTranslatorJobs($cuser)
    {
        $jobs = Job::getTranslatorJobs($cuser->id, 'new');
        return $jobs->pluck('jobs')->all();
    }

    /**
     * Get job history for a user.
     *
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $this->getCustomerJobHistory($cuser);
                $usertype = 'customer';
                return $this->prepareCustomerHistoryResponse($emergencyJobs, $jobs, $cuser, $usertype);
            } elseif ($cuser->is('translator')) {
                $jobs = $this->getTranslatorJobHistory($cuser, $page);
                $usertype = 'translator';
                return $this->prepareTranslatorHistoryResponse($emergencyJobs, $jobs, $cuser, $usertype);
            }
        }

        return [];
    }

    private function getCustomerJobHistory($cuser)
    {
        return $cuser->jobs()
            ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15);
    }

    private function getTranslatorJobHistory($cuser, $page)
    {
        $jobs = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $page);
        return $jobs;
    }

    private function prepareCustomerHistoryResponse($emergencyJobs, $jobs, $cuser, $usertype)
    {
        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => [],
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => 0,
            'pagenum' => 0,
        ];
    }

    private function prepareTranslatorHistoryResponse($emergencyJobs, $jobs, $cuser, $usertype)
    {
        $totalJobs = $jobs->total();
        $numPages = ceil($totalJobs / 15);

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $jobs,
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => $numPages,
            'pagenum' => $page,
        ];
    }

    /**
     * Store a job for a user.
     *
     * @param User $user
     * @param array $data
     * @return array
     */
    public function store(User $user, array $data)
    {
        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return $this->failResponse('Translator cannot create booking');
        }

        if ($missingField = $this->validateStoreData($data)) {
            return $this->failResponse("You must fill in all fields", $missingField);
        }

        $this->prepareDataForStorage($data, $user);

        $job = $user->jobs()->create($data);

        return $this->successResponse($job->id, $data, $user);
    }

    private function validateStoreData($data)
    {
        if (empty($data['from_language_id'])) {
            return 'from_language_id';
        }
        if ($data['immediate'] == 'no' && (empty($data['due_date']) || empty($data['due_time']) || empty($data['duration']))) {
            return 'due_date';
        }
        if ($data['immediate'] == 'yes' && empty($data['duration'])) {
            return 'duration';
        }
        if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
            return 'customer_phone_type';
        }
        return null;
    }

    private function prepareDataForStorage(&$data, $user)
    {
        $data['customer_phone_type'] = $data['customer_phone_type'] ?? 'no';
        $data['customer_physical_type'] = $data['customer_physical_type'] ?? 'no';
        $data['due'] = $this->calculateDueDate($data);
        $data['gender'] = $this->determineGender($data['job_for']);
        $data['certified'] = $this->determineCertification($data['job_for']);
        $data['job_type'] = $this->determineJobType($user->userMeta->consumer_type);
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        $data['by_admin'] = $data['by_admin'] ?? 'no';
    }

    private function calculateDueDate($data)
    {
        if ($data['immediate'] == 'yes') {
            return now()->addMinutes(5)->format('Y-m-d H:i:s');
        }

        $due = Carbon::createFromFormat('m/d/Y H:i', "{$data['due_date']} {$data['due_time']}");
        if ($due->isPast()) {
            throw new \Exception("Can't create booking in the past");
        }
        return $due->format('Y-m-d H:i:s');
    }

    private function determineGender($jobFor)
    {
        return in_array('male', $jobFor) ? 'male' : (in_array('female', $jobFor) ? 'female' : null);
    }

    private function determineCertification($jobFor)
    {
        if (in_array('normal', $jobFor)) return 'normal';
        if (in_array('certified', $jobFor)) return 'yes';
        if (in_array('certified_in_law', $jobFor)) return 'law';
        if (in_array('certified_in_health', $jobFor)) return 'health';
        return null;
    }

    private function determineJobType($consumerType)
    {
        return $consumerType == 'rwsconsumer' ? 'rws' : 'unpaid';
    }

    private function failResponse($message, $missingField = null)
    {
        return [
            'status' => 'fail',
            'message' => $message,
            'missingField' => $missingField,
        ];
    }

    private function successResponse($jobId, $data, $user)
    {
        Event::dispatch(new JobWasCreated($job, $data, $user));
        $this->logger->addInfo('User #' . $user->id . ' created a new booking: ' . $jobId);

        return [
            'status' => 'success',
            'id' => $jobId,
        ];
    }

    /**
     * End a job session.
     *
     * @param array $postData
     * @return array
     */
    public function endJob(array $postData)
    {
        $completedDate = now()->format('Y-m-d H:i:s');
        $job = Job::with('translatorJobRel')->find($postData['job_id']);

        $diff = $this->calculateTimeDifference($job, $completedDate);

        $this->updateJobSession($job, $diff, $completedDate);

        if ($job->session_time > 0) {
            Event::dispatch(new SessionEnded($job, ($postData['userid'] == $job->user_id) ? $job->user_id : $job->translatorJobRel->first()->user_id));
        }

        return [
            'status' => 'success',
        ];
    }

    private function calculateTimeDifference($job, $completedDate)
    {
        $due = Carbon::parse($job->due);
        return $due->diffInMinutes($completedDate);
    }

    private function updateJobSession($job, $diff, $completedDate)
    {
        $job->update([
            'end_at' => $completedDate,
            'status' => 'completed',
            'session_time' => $diff,
        ]);
    }
    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];

        try {
            $job_detail = Job::with('translatorJobRel')->findOrFail($jobid);
            $duedate = $job_detail->due;
            $start = new DateTime($duedate);
            $end = new DateTime($completeddate);
            $interval = $start->diff($end)->format('%h:%i:%s');

            $job_detail->end_at = $completeddate;
            $job_detail->status = 'not_carried_out_customer';

            $translatorJobRel = $job_detail->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->firstOrFail();
            $translatorJobRel->completed_at = $completeddate;
            $translatorJobRel->completed_by = $translatorJobRel->user_id;

            $job_detail->save();
            $translatorJobRel->save();

            $response['status'] = 'success';
        } catch (Exception $e) {
            Log::error('Error in customerNotCall method', ['error' => $e->getMessage(), 'post_data' => $post_data]);
            $response['status'] = 'error';
            $response['message'] = 'An error occurred while processing the request.';
        }

        return $response;
    }
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs->filterFeedback($requestdata['feedback'] ?? 'false')
                ->filterById($requestdata['id'] ?? '')
                ->filterByLang($requestdata['lang'] ?? [])
                ->filterByStatus($requestdata['status'] ?? [])
                ->filterByExpiredAt($requestdata['expired_at'] ?? '')
                ->filterByWillExpireAt($requestdata['will_expire_at'] ?? '')
                ->filterByCustomerEmail($requestdata['customer_email'] ?? [])
                ->filterByTranslatorEmail($requestdata['translator_email'] ?? [])
                ->filterByTimeType($requestdata['filter_timetype'] ?? '', $requestdata['from'] ?? '', $requestdata['to'] ?? '')
                ->filterByJobType($requestdata['job_type'] ?? [])
                ->filterByPhysical($requestdata['physical'] ?? null)
                ->filterByPhone($requestdata['phone'] ?? null, $requestdata['physical'] ?? null)
                ->filterByFlagged($requestdata['flagged'] ?? null)
                ->filterByDistance($requestdata['distance'] ?? '')
                ->filterBySalary($requestdata['salary'] ?? '')
                ->filterByConsumerType($requestdata['consumer_type'] ?? '')
                ->filterByBookingType($requestdata['booking_type'] ?? '');

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                return ['count' => $allJobs->count()];
            }

            $allJobs->withAllRelationships()->orderBy('created_at', 'desc');

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        } else {
            $allJobs->filterById($requestdata['id'] ?? '')
                ->when($consumer_type == 'RWS', function ($query) {
                    return $query->where('job_type', 'rws');
                }, function ($query) {
                    return $query->where('job_type', 'unpaid');
                })
                ->filterFeedback($requestdata['feedback'] ?? 'false')
                ->filterByLang($requestdata['lang'] ?? [])
                ->filterByStatus($requestdata['status'] ?? [])
                ->filterByJobType($requestdata['job_type'] ?? [])
                ->filterByCustomerEmail([$requestdata['customer_email'] ?? ''])
                ->filterByTimeType($requestdata['filter_timetype'] ?? '', $requestdata['from'] ?? '', $requestdata['to'] ?? '')
                ->filterByWillExpireAt($requestdata['will_expire_at'] ?? '');

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                return ['count' => $allJobs->count()];
            }

            $allJobs->withAllRelationships()->orderBy('created_at', 'desc');

            if ($limit == 'all') {
                $allJobs = $allJobs->get();
            } else {
                $allJobs = $allJobs->paginate(15);
            }
        }

        return $allJobs;
    }
    public function alerts()
    {
        $requestdata = Request::all();
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && $cuser->is('superadmin')) {
            $jobsQuery = Job::filterBySessionTime()
                ->filterByLanguage($requestdata['lang'] ?? [])
                ->filterByStatus($requestdata['status'] ?? [])
                ->filterByUserEmail($requestdata['customer_email'] ?? '')
                ->filterByTranslatorEmail($requestdata['translator_email'] ?? '')
                ->filterByTimeType($requestdata['filter_timetype'] ?? '', $requestdata['from'] ?? '', $requestdata['to'] ?? '')
                ->filterByJobType($requestdata['job_type'] ?? [])
                ->with('language')
                ->where('ignore', 0)
                ->orderBy('created_at', 'desc');

            $allJobs = $jobsQuery->paginate(15);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }
    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }
    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }
    public function bookingExpireNoAccepted()
    {
        $languages = Language::active()->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = User::where('user_type', '1')->pluck('email');
        $all_translators = User::where('user_type', '2')->pluck('email');

        $cuser = Auth::user();

        $allJobs = Job::query()
            ->filterByExpiredAt(Carbon::now())
            ->filterByLang($requestdata['lang'] ?? [])
            ->filterByStatus($requestdata['status'] ?? [])
            ->filterByCustomerEmail([$requestdata['customer_email'] ?? ''])
            ->filterByTranslatorEmail([$requestdata['translator_email'] ?? ''])
            ->filterByTimeType(
                $requestdata['filter_timetype'] ?? null,
                $requestdata['from'] ?? null,
                $requestdata['to'] ?? null
            )
            ->filterByJobType($requestdata['job_type'] ?? [])
            ->where('status', 'pending')
            ->with(['language'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata,
        ];
    }
    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::findOrFail($jobId);
        $currentTimestamp = now();
        $willExpireAt = TeHelper::willExpireAt($job->due, $currentTimestamp);

        $reopenData = [
            'status' => 'pending',
            'created_at' => $currentTimestamp,
            'will_expire_at' => $willExpireAt,
        ];

        if ($job->status !== 'timedout') {
            // Update existing job
            $job->update($reopenData);
            $newJobId = $jobId;
        } else {
            // Recreate the job for "timedout" status
            $newJob = $job->replicate();
            $newJob->status = 'pending';
            $newJob->created_at = $currentTimestamp;
            $newJob->updated_at = $currentTimestamp;
            $newJob->will_expire_at = $willExpireAt;
            $newJob->cust_16_hour_email = 0;
            $newJob->cust_48_hour_email = 0;
            $newJob->admin_comments = "This booking is a reopening of booking #$jobId";

            $newJob->save();
            $newJobId = $newJob->id;
        }

        // Update translator relations
        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $currentTimestamp]);

        // Create a new translator-job relationship
        Translator::create([
            'created_at' => $currentTimestamp,
            'will_expire_at' => $willExpireAt,
            'updated_at' => $currentTimestamp,
            'user_id' => $userId,
            'job_id' => $newJobId,
            'cancel_at' => $currentTimestamp,
        ]);

        // Notify admin
        $this->sendNotificationByAdminCancelJob($newJobId);

        return ["Tolk cancelled!"];
    }
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);

        return sprintf($format, $hours, $minutes);
    }


    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::with(['user.userMeta'])->findOrFail($jobId);
        $userMeta = $job->user->userMeta;

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city ?? null,
            'customer_type' => $userMeta->customer_type ?? null,
            'due_date' => $job->due_date,
            'due_time' => $job->due_time,
            'job_for' => $this->getJobFor($job)
        ];

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function getJobFor($job)
    {
        $jobFor = [];

        if ($job->gender) {
            $jobFor[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            if ($job->certified === 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified === 'yes' ? 'certified' : $job->certified;
            }
        }

        return $jobFor;
    }
    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }
    public function getPotentialJobIdsWithUserId($user_id)
    {
        // Fetch user meta information
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        if (!$user_meta) {
            return []; // Return early if user meta is not found
        }

        // Determine job type based on translator type
        $job_type = $this->getJobTypeBasedOnTranslator($user_meta->translator_type);

        // Fetch user languages
        $user_languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->toArray();

        // Fetch potential job IDs
        $job_ids = Job::getJobs(
            $user_id,
            $job_type,
            'pending',
            $user_languages,
            $user_meta->gender,
            $user_meta->translator_level
        );

        // Filter job IDs based on translator town checks
        $filtered_job_ids = $this->filterJobsByTownCheck($job_ids, $user_id);

        // Convert job IDs to objects
        return TeHelper::convertJobIdsInObjs($filtered_job_ids);
    }

    /**
     * Determine job type based on translator type
     *
     * @param string $translator_type
     * @return string
     */
    private function getJobTypeBasedOnTranslator($translator_type)
    {
        return match ($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };
    }

    /**
     * Filter jobs based on translator town checks
     *
     * @param array $job_ids
     * @param int $user_id
     * @return array
     */
    private function filterJobsByTownCheck($job_ids, $user_id)
    {
        return array_filter($job_ids, function ($job) use ($user_id) {
            $job = Job::find($job->id);
            if (!$job) {
                return false; // Exclude if the job is not found
            }

            $check_town = Job::checkTowns($job->user_id, $user_id);
            $is_physical_job = $job->customer_physical_type === 'yes';
            $has_no_phone_type = $job->customer_phone_type === 'no' || $job->customer_phone_type === '';

            return !($is_physical_job && !$check_town && $has_no_phone_type);
        });
    }
    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = Job::with(['user.userMeta'])->findOrFail($jobId);
        $userMeta = $job->user->userMeta;

        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city ?? null,
            'customer_type' => $userMeta->customer_type ?? null,
            'due_date' => $job->due_date,
            'due_time' => $job->due_time,
            'job_for' => $this->getJobFor($job)
        ];

        $this->sendNotificationTranslator($job, $data, '*');
    }

    public function sendNotificationTranslator($job, $data = [], $excludeUserId)
    {
        $users = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '<>', $excludeUserId)
            ->get();

        $translatorArray = [];            // Suitable translators (no need to delay push)
        $delayTranslatorArray = [];       // Suitable translators (need to delay push)

        foreach ($users as $user) {
            if (!$this->isNeedToSendPush($user->id)) continue;

            $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');
            if ($data['immediate'] === 'yes' && $notGetEmergency === 'yes') continue;

            $jobs = $this->getPotentialJobIdsWithUserId($user->id);

            foreach ($jobs as $potentialJob) {
                if ($job->id === $potentialJob->id) {
                    $jobForTranslator = Job::assignedToPaticularTranslator($user->id, $potentialJob->id);

                    if ($jobForTranslator === 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($user->id, $potentialJob);

                        if ($jobChecker !== 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($user->id)) {
                                $delayTranslatorArray[] = $user;
                            } else {
                                $translatorArray[] = $user;
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgContents = $data['immediate'] === 'no'
            ? 'Ny bokning för ' . $data['language'] . ' tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . ' tolk ' . $data['duration'] . 'min';

        $msgText = ["en" => $msgContents];
        $this->initializeLogger('push_logger');
        $this->logInfo('Push send for job ' . $job->id, [$translatorArray, $delayTranslatorArray, $msgText, $data]);
        $this->notificationService->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->notificationService->sendPushNotificationToSpecificUsers($delayTranslatorArray, $job->id, $data, $msgText, true);
    }
}
