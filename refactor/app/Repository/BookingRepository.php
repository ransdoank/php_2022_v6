<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;



// Improve:
// -------------------------------------------
use Psr\Log\LoggerInterface;
use DTApi\Constants\UserRoles;
use DTApi\Constants\JobTypes;
use DTApi\Http\Requests\StoreJobRequest; // assume we created this Form Request for validation
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use DTApi\Jobs\SendPushNotification; // This should be an existing queued job.
use DTApi\Helpers\SendSMSHelper;
use Illuminate\Support\Facades\Http;
use DTApi\Services\NotificationService; // Assuming this is the new service
use Illuminate\Support\Facades\Lang;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    // function __construct(Job $model, MailerInterface $mailer)
    // {
    //     parent::__construct($model);
    //     $this->mailer = $mailer;
    //     $this->logger = new Logger('admin_logger');

    //     $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    //     $this->logger->pushHandler(new FirePHPHandler());
    // }
    // Improve:
    // -------------------------------------------
    private $teHelper;

    public function __construct(Job $model, MailerInterface $mailer, LoggerInterface $logger, TeHelper $teHelper)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->teHelper = $teHelper;
    }
    $this->app->bind('BookingRepository', function ($app) {
        $model = $app->make(Job::class);
        $mailer = $app->make(MailerInterface::class);
    
        $logger = new Logger('admin_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler($app->make(FirePHPHandler::class));
    
        return new BookingRepository($model, $mailer, $logger);
    });

    /**
     * @param $user_id
     * @return array
     */
    // public function getUsersJobs($user_id)
    // {
    //     $cuser = User::find($user_id);
    //     $usertype = '';
    //     $emergencyJobs = array();
    //     $noramlJobs = array();
    //     if ($cuser && $cuser->is('customer')) {
    //         $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')->whereIn('status', ['pending', 'assigned', 'started'])->orderBy('due', 'asc')->get();
    //         $usertype = 'customer';
    //     } elseif ($cuser && $cuser->is('translator')) {
    //         $jobs = Job::getTranslatorJobs($cuser->id, 'new');
    //         $jobs = $jobs->pluck('jobs')->all();
    //         $usertype = 'translator';
    //     }
    //     if ($jobs) {
    //         foreach ($jobs as $jobitem) {
    //             if ($jobitem->immediate == 'yes') {
    //                 $emergencyJobs[] = $jobitem;
    //             } else {
    //                 $noramlJobs[] = $jobitem;
    //             }
    //         }
    //         $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
    //             $item['usercheck'] = Job::checkParticularJob($user_id, $item);
    //         })->sortBy('due')->all();
    //     }
    //     return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    // }
    // Improve:
    // -------------------------------------------
    public function getUsersJobs(int $user_id): array
    {
        $user = User::findOrFail($user_id);

        $usertype = $user->type; // Assuming 'type' is a valid attribute for the user's role
        $jobsQuery = $user->jobs();

        if ($usertype === 'customer') {
            $jobs = $jobsQuery->with([
                'user.userMeta', 'user.average', 'translatorJobRel.user.average',
                'language', 'feedback'
            ])->whereIn('status', ['pending', 'assigned', 'started'])
            ->orderBy('due', 'asc')->get();
        } elseif ($usertype === 'translator') {
            $jobs = Job::getTranslatorJobs($user->id, 'new');
        }

        [$emergencyJobs, $normalJobs] = $this->categorizeJobs($jobs);

        // add 'usercheck' for normal jobs
        $normalJobs = $this->applyUserCheck($normalJobs, $user_id);

        return [
            'emergencyJobs' => $emergencyJobs, 
            'normalJobs' => $normalJobs,
            'userType' => $usertype
        ];
    }

    // New Method for categorizing emergency and normal jobs
    private function categorizeJobs($jobs): array
    {
        $emergencyJobs = [];
        $normalJobs = [];

        foreach ($jobs as $job) {
            if ($job->immediate === 'yes') {
                $emergencyJobs[] = $job;
            } else {
                $normalJobs[] = $job;
            }
        }

        return [$emergencyJobs, $normalJobs];
    }

    // New Method to add 'usercheck' to jobs
    private function applyUserCheck($normalJobs, int $user_id): array
    {
        return collect($normalJobs)->map(function ($job) use ($user_id) {
            $job['usercheck'] = Job::checkParticularJob($user_id, $job);
            return $job;
        })->sortBy('due')->all();
    }

    /**
     * @param $user_id
     * @return array
     */
    // public function getUsersJobsHistory($user_id, Request $request)
    // {
    //     $page = $request->get('page');
    //     if (isset($page)) {
    //         $pagenum = $page;
    //     } else {
    //         $pagenum = "1";
    //     }
    //     $cuser = User::find($user_id);
    //     $usertype = '';
    //     $emergencyJobs = array();
    //     $noramlJobs = array();
    //     if ($cuser && $cuser->is('customer')) {
    //         $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
    //         $usertype = 'customer';
    //         return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
    //     } elseif ($cuser && $cuser->is('translator')) {
    //         $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
    //         $totaljobs = $jobs_ids->total();
    //         $numpages = ceil($totaljobs / 15);

    //         $usertype = 'translator';

    //         $jobs = $jobs_ids;
    //         $noramlJobs = $jobs_ids;
    //     //    $jobs['data'] = $noramlJobs;
    //     //    $jobs['total'] = $totaljobs;
    //         return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
    //     }
    // }
    // Improve:
    // -------------------------------------------
    public function getUsersJobsHistory(int $user_id)
    {
        $user = User::findOrFail($user_id);
        
        if ($user->is('customer')) {
            $jobs = $user->jobs()->with([
                'user.userMeta',
                'user.average',
                'translatorJobRel.user.average',
                'language',
                'feedback',
                'distance'
            ])->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')->paginate();

            return [
                'jobs' => $jobs, 
                'userType' => 'customer'
            ];
        } elseif ($user->is('translator')) {
            $jobs = Job::getTranslatorJobsHistoric($user->id, 'historic');// pagination handled within the method
            
            return [
                'jobs' => $jobs, 
                'userType' => 'translator',
                'totalPages' => $jobs->lastPage(), // Get the last page from the pagination
                'currentPage' => $jobs->currentPage()
            ];
        }
        
        // Consider an else case or exception if a user is neither a customer nor a translator
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    // public function store($user, $data)
    // {

    //     $immediatetime = 5;
    //     $consumer_type = $user->userMeta->consumer_type;
    //     if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
    //         $cuser = $user;

    //         if (!isset($data['from_language_id'])) {
    //             $response['status'] = 'fail';
    //             $response['message'] = "Du måste fylla in alla fält";
    //             $response['field_name'] = "from_language_id";
    //             return $response;
    //         }
    //         if ($data['immediate'] == 'no') {
    //             if (isset($data['due_date']) && $data['due_date'] == '') {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Du måste fylla in alla fält";
    //                 $response['field_name'] = "due_date";
    //                 return $response;
    //             }
    //             if (isset($data['due_time']) && $data['due_time'] == '') {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Du måste fylla in alla fält";
    //                 $response['field_name'] = "due_time";
    //                 return $response;
    //             }
    //             if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Du måste göra ett val här";
    //                 $response['field_name'] = "customer_phone_type";
    //                 return $response;
    //             }
    //             if (isset($data['duration']) && $data['duration'] == '') {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Du måste fylla in alla fält";
    //                 $response['field_name'] = "duration";
    //                 return $response;
    //             }
    //         } else {
    //             if (isset($data['duration']) && $data['duration'] == '') {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Du måste fylla in alla fält";
    //                 $response['field_name'] = "duration";
    //                 return $response;
    //             }
    //         }
    //         if (isset($data['customer_phone_type'])) {
    //             $data['customer_phone_type'] = 'yes';
    //         } else {
    //             $data['customer_phone_type'] = 'no';
    //         }

    //         if (isset($data['customer_physical_type'])) {
    //             $data['customer_physical_type'] = 'yes';
    //             $response['customer_physical_type'] = 'yes';
    //         } else {
    //             $data['customer_physical_type'] = 'no';
    //             $response['customer_physical_type'] = 'no';
    //         }

    //         if ($data['immediate'] == 'yes') {
    //             $due_carbon = Carbon::now()->addMinute($immediatetime);
    //             $data['due'] = $due_carbon->format('Y-m-d H:i:s');
    //             $data['immediate'] = 'yes';
    //             $data['customer_phone_type'] = 'yes';
    //             $response['type'] = 'immediate';

    //         } else {
    //             $due = $data['due_date'] . " " . $data['due_time'];
    //             $response['type'] = 'regular';
    //             $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
    //             $data['due'] = $due_carbon->format('Y-m-d H:i:s');
    //             if ($due_carbon->isPast()) {
    //                 $response['status'] = 'fail';
    //                 $response['message'] = "Can't create booking in past";
    //                 return $response;
    //             }
    //         }
    //         if (in_array('male', $data['job_for'])) {
    //             $data['gender'] = 'male';
    //         } else if (in_array('female', $data['job_for'])) {
    //             $data['gender'] = 'female';
    //         }
    //         if (in_array('normal', $data['job_for'])) {
    //             $data['certified'] = 'normal';
    //         }
    //         else if (in_array('certified', $data['job_for'])) {
    //             $data['certified'] = 'yes';
    //         } else if (in_array('certified_in_law', $data['job_for'])) {
    //             $data['certified'] = 'law';
    //         } else if (in_array('certified_in_helth', $data['job_for'])) {
    //             $data['certified'] = 'health';
    //         }
    //         if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
    //             $data['certified'] = 'both';
    //         }
    //         else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
    //         {
    //             $data['certified'] = 'n_law';
    //         }
    //         else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
    //         {
    //             $data['certified'] = 'n_health';
    //         }
    //         if ($consumer_type == 'rwsconsumer')
    //             $data['job_type'] = 'rws';
    //         else if ($consumer_type == 'ngo')
    //             $data['job_type'] = 'unpaid';
    //         else if ($consumer_type == 'paid')
    //             $data['job_type'] = 'paid';
    //         $data['b_created_at'] = date('Y-m-d H:i:s');
    //         if (isset($due))
    //             $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
    //         $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

    //         $job = $cuser->jobs()->create($data);

    //         $response['status'] = 'success';
    //         $response['id'] = $job->id;
    //         $data['job_for'] = array();
    //         if ($job->gender != null) {
    //             if ($job->gender == 'male') {
    //                 $data['job_for'][] = 'Man';
    //             } else if ($job->gender == 'female') {
    //                 $data['job_for'][] = 'Kvinna';
    //             }
    //         }
    //         if ($job->certified != null) {
    //             if ($job->certified == 'both') {
    //                 $data['job_for'][] = 'normal';
    //                 $data['job_for'][] = 'certified';
    //             } else if ($job->certified == 'yes') {
    //                 $data['job_for'][] = 'certified';
    //             } else {
    //                 $data['job_for'][] = $job->certified;
    //             }
    //         }

    //         $data['customer_town'] = $cuser->userMeta->city;
    //         $data['customer_type'] = $cuser->userMeta->customer_type;

    //         //Event::fire(new JobWasCreated($job, $data, '*'));

    //     //    $this->sendNotificationToSuitableTranslators($job->id, $data, '*');// send Push for New job posting
    //     } else {
    //         $response['status'] = 'fail';
    //         $response['message'] = "Translator can not create booking";
    //     }
    //     return $response;
    // }
    // Improve:
    // -------------------------------------------
    // Use dependency injection to get the current request
    public function store(User $user, StoreJobRequest $request)
    {
        $data = $request->validated(); // Use Form Request validation

        // Business logic
        $jobType = $this->determineJobType($user, $data);
        $certificationType = $this->determineCertification($data);
        $due = $this->calculateDueDate($data);

        // Creating the job if all validations passed
        $job = $user->jobs()->create(array_merge($data, [
            'job_type' => $jobType,
            'certified' => $certificationType,
            'due' => $due->format('Y-m-d H:i:s'),
            // other fields computed from the logic
        ]));

        // Response formation
        return [
            'status' => 'success',
            'id' => $job->id,
            // additional required response data
        ];
    }
    protected function determineJobType(User $user, array $data): string
    {
        $consumerType = $user->userMeta->consumer_type;

        switch ($consumerType) {
            case 'rwsconsumer':
                return 'rws';
            case 'ngo':
                return 'unpaid';
            case 'paid':
                return 'paid';
            default:
                // This default case can handle any other case or throw an exception.
                return 'unknown';
        }
    }

    /**
     * Determines the certification type based on the job attributes.
     *
     * @param  array $data
     * @return string
     */
    protected function determineCertification(array $data): string
    {
        $certifiedTypes = [
            'normal' => 'normal',
            'certified' => 'yes',
            'certified_in_law' => 'law',
            'certified_in_health' => 'health',
            'both' => 'both',
            'n_law' => 'n_law',
            'n_health' => 'n_health',
        ];

        foreach ($certifiedTypes as $key => $value) {
            if (in_array($key, $data['job_for'])) {
                // Here we return the first matched value, but depending on the
                // requirements, you might need to handle multiple matches differently
                return $value;
            }
        }

        // If no type is found, return a default or throw an exception.
        return 'undefined';
    }
    /**
     * Calculates the due date of the job based on provided data.
     *
     * @param  array $data
     * @return Carbon
     */
    protected function calculateDueDate(array $data): Carbon
    {
        if ($data['immediate'] === 'yes') {
            // If the job is immediate, add the predefined number of minutes to the current time.
            // Assuming the value for "immediate" jobs is stored in a constant.
            $immediateMinutes = 5; // You can move this to a configuration or environment setting
            return Carbon::now()->addMinutes($immediateMinutes);
        } else {
            // Otherwise, create the due date from the provided due_date and due_time.
            // Ensure these keys exist in $data or perform additional validation as required.
            $dueDateTime = $data['due_date'] . " " . $data['due_time'];
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $dueDateTime);

            if ($dueCarbon->isPast()) {
                // Consider throwing an exception or handling this error as needed
                throw new \Exception("Can't create booking in the past");
            }

            return $dueCarbon;
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    // public function storeJobEmail($data)
    // {
    //     $user_type = $data['user_type'];
    //     $job = Job::findOrFail(@$data['user_email_job_id']);
    //     $job->user_email = @$data['user_email'];
    //     $job->reference = isset($data['reference']) ? $data['reference'] : '';
    //     $user = $job->user()->get()->first();
    //     if (isset($data['address'])) {
    //         $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
    //         $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
    //         $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
    //     }
    //     $job->save();

    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //         $name = $user->name;
    //     } else {
    //         $email = $user->email;
    //         $name = $user->name;
    //     }
    //     $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
    //     $send_data = [
    //         'user' => $user,
    //         'job'  => $job
    //     ];
    //     $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

    //     $response['type'] = $user_type;
    //     $response['job'] = $job;
    //     $response['status'] = 'success';
    //     $data = $this->jobToData($job);
    //     Event::fire(new JobWasCreated($job, $data, '*'));
    //     return $response;

    // }
    // Improve:
    // -------------------------------------------
    public function storeJobEmail(array $data)
    {
        // Input validation should happen outside this method, usually in a Request class...

        $job = Job::findOrFail($data['user_email_job_id']);
        $this->updateJob($job, $data);
        
        $this->sendConfirmationEmail($job);

        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];

        // Trigger any necessary event
        Event::dispatch(new JobWasCreated($job, $data, '*'));

        return $response;
    }
    protected function updateJob(Job $job, array $data)
    {
        $user = $job->user()->firstOrFail();

        // Update job attributes with data or fallback to user metadata
        $job->user_email = $data['user_email'] ?? $user->email;
        $job->reference = $data['reference'] ?? '';
        $job->address = $data['address'] ?? $user->userMeta->address;
        $job->instructions = $data['instructions'] ?? $user->userMeta->instructions;
        $job->town = $data['town'] ?? $user->userMeta->city;
        
        $job->save();
    }
    protected function sendConfirmationEmail(Job $job)
    {
        $email = $job->user_email ?? $job->user->email;
        $name = $job->user->name;

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $job->user,
            'job' => $job,
        ];

        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
    }

    /**
     * @param $job
     * @return array
     */
    // public function jobToData($job)
    // {

    //     $data = array();            // save job's information to data for sending Push
    //     $data['job_id'] = $job->id;
    //     $data['from_language_id'] = $job->from_language_id;
    //     $data['immediate'] = $job->immediate;
    //     $data['duration'] = $job->duration;
    //     $data['status'] = $job->status;
    //     $data['gender'] = $job->gender;
    //     $data['certified'] = $job->certified;
    //     $data['due'] = $job->due;
    //     $data['job_type'] = $job->job_type;
    //     $data['customer_phone_type'] = $job->customer_phone_type;
    //     $data['customer_physical_type'] = $job->customer_physical_type;
    //     $data['customer_town'] = $job->town;
    //     $data['customer_type'] = $job->user->userMeta->customer_type;

    //     $due_Date = explode(" ", $job->due);
    //     $due_date = $due_Date[0];
    //     $due_time = $due_Date[1];

    //     $data['due_date'] = $due_date;
    //     $data['due_time'] = $due_time;

    //     $data['job_for'] = array();
    //     if ($job->gender != null) {
    //         if ($job->gender == 'male') {
    //             $data['job_for'][] = 'Man';
    //         } else if ($job->gender == 'female') {
    //             $data['job_for'][] = 'Kvinna';
    //         }
    //     }
    //     if ($job->certified != null) {
    //         if ($job->certified == 'both') {
    //             $data['job_for'][] = 'Godkänd tolk';
    //             $data['job_for'][] = 'Auktoriserad';
    //         } else if ($job->certified == 'yes') {
    //             $data['job_for'][] = 'Auktoriserad';
    //         } else if ($job->certified == 'n_health') {
    //             $data['job_for'][] = 'Sjukvårdstolk';
    //         } else if ($job->certified == 'law' || $job->certified == 'n_law') {
    //             $data['job_for'][] = 'Rätttstolk';
    //         } else {
    //             $data['job_for'][] = $job->certified;
    //         }
    //     }

    //     return $data;

    // }
    // Improve:
    // -------------------------------------------
    public function jobToData($job)
    {
        // Initial validation to ensure $job is valid and has necessary relationships loaded
        if (!$job || !$job->user || !$job->user->userMeta) {
            throw new \InvalidArgumentException('Invalid job or job relationships are not loaded properly.');
        }

        // Using Laravel's collection methods to safely access job attributes
        $data = collect($job->attributesToArray())
            ->only([
                'id', 'from_language_id', 'immediate', 'duration', 'status', 'gender',
                'certified', 'due', 'job_type', 'customer_phone_type', 'customer_physical_type',
            ])
            ->put('customer_town', $job->town)
            ->put('customer_type', $job->user->userMeta->customer_type)
            ->toArray();

        // Extract the due date and time using Carbon for safety and localization
        $dueCarbon = Carbon::parse($job->due);
        $data['due_date'] = $dueCarbon->toDateString();
        $data['due_time'] = $dueCarbon->toTimeString();

        $data['job_for'] = $this->getJobForDescriptiveValues($job);

        return $data;
    }
    /**
     * Get descriptive values for the 'job for' data based on job attributes.
     *
     * @param $job
     * @return array
     */
    protected function getJobForDescriptiveValues($job)
    {
        $descriptions = [];

        if (!is_null($job->gender)) {
            $descriptions[] = __('jobs.gender_' . Str::slug($job->gender)); // Using translation files
        }

        if (!is_null($job->certified)) {
            $certificationKey = 'jobs.certification_' . Str::slug($job->certified);
            $descriptions[] = __('jobs.certification_type',['type' => __('' . $certificationKey)]); // Again, using translation files
        }

        return $descriptions;
    }

    /**
     * @param array $post_data
     */
    // public function jobEnd($post_data = array())
    // {
    //     $completeddate = date('Y-m-d H:i:s');
    //     $jobid = $post_data["job_id"];
    //     $job_detail = Job::with('translatorJobRel')->find($jobid);
    //     $duedate = $job_detail->due;
    //     $start = date_create($duedate);
    //     $end = date_create($completeddate);
    //     $diff = date_diff($end, $start);
    //     $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
    //     $job = $job_detail;
    //     $job->end_at = date('Y-m-d H:i:s');
    //     $job->status = 'completed';
    //     $job->session_time = $interval;

    //     $user = $job->user()->get()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //     $session_explode = explode(':', $job->session_time);
    //     $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
    //     $data = [
    //         'user'         => $user,
    //         'job'          => $job,
    //         'session_time' => $session_time,
    //         'for_text'     => 'faktura'
    //     ];
    //     $mailer = new AppMailer();
    //     $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

    //     $job->save();

    //     $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

    //     Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));

    //     $user = $tr->user()->first();
    //     $email = $user->email;
    //     $name = $user->name;
    //     $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //     $data = [
    //         'user'         => $user,
    //         'job'          => $job,
    //         'session_time' => $session_time,
    //         'for_text'     => 'lön'
    //     ];
    //     $mailer = new AppMailer();
    //     $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

    //     $tr->completed_at = $completeddate;
    //     $tr->completed_by = $post_data['userid'];
    //     $tr->save();
    // }
    // Improve:
    // -------------------------------------------
    public function jobEnd(Request $request)
    {
        $request->validate([
            'job_id' => 'required',
            'userid' => 'required',
        ]);

        $job = Job::with(['user', 'translatorJobRel.user'])->findOrFail($request->job_id);
        $job->end_at = Carbon::now();
        $job->status = 'completed';
        $job->session_time = $job->end_at->diff($job->due)->format('%H:%I:%S');

        $user = $job->user;
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        // Assuming AppMailer is injected via dependency injection or resolved from the service container
        $mailer = resolve(AppMailer::class);

        $subject = 'Information om avslutad tolkning för bokningsnummer #'.$job->id;
        $session_time = Carbon::createFromFormat('H:i:s', $job->session_time)->format('G \t\i\m i \m\i\n');

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura',
        ];

        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
        $job->save();

        $tr = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->firstOrFail();
        $tr->completed_at = Carbon::now();
        $tr->completed_by = $request->userid;
        $tr->save();

        Event::dispatch(new SessionEnded($job, ($request->userid === $job->user_id) ? $tr->user->id : $job->user_id));

        // Separate the email logic if a translator needs to receive an email as well.
        // Notify Translator...

        return response()->json(['message' => 'Job ended successfully.', 'job' => $job]);
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    // public function getPotentialJobIdsWithUserId($user_id)
    // {
    //     $user_meta = UserMeta::where('user_id', $user_id)->first();
    //     $translator_type = $user_meta->translator_type;
    //     $job_type = 'unpaid';
    //     if ($translator_type == 'professional')
    //         $job_type = 'paid';   /*show all jobs for professionals.*/
    //     else if ($translator_type == 'rwstranslator')
    //         $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
    //     else if ($translator_type == 'volunteer')
    //         $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

    //     $languages = UserLanguages::where('user_id', '=', $user_id)->get();
    //     $userlanguage = collect($languages)->pluck('lang_id')->all();
    //     $gender = $user_meta->gender;
    //     $translator_level = $user_meta->translator_level;
    //     $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
    //     foreach ($job_ids as $k => $v)     // checking translator town
    //     {
    //         $job = Job::find($v->id);
    //         $jobuserid = $job->user_id;
    //         $checktown = Job::checkTowns($jobuserid, $user_id);
    //         if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
    //             unset($job_ids[$k]);
    //         }
    //     }
    //     $jobs = TeHelper::convertJobIdsInObjs($job_ids);
    //     return $jobs;
    // }
    // Improve:
    // -------------------------------------------
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->firstOrFail();
        $translator_type = $user_meta->translator_type;
        
        $job_types = [
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
        ];

        $job_type = $job_types[$translator_type] ?? $job_type; // Default to 'unpaid' if not set

        $user_languages = UserLanguages::where('user_id', $user_id)
                                        ->pluck('lang_id')
                                        ->toArray();

        $job_ids = Job::getJobs($user_id, $job_type, 'pending',
                                $user_languages, $user_meta->gender,
                                $user_meta->translator_level);

        // Filter out jobs within the same query/logic without the need for a foreach loop
        $filtered_job_ids = Job::whereIn('id', $job_ids->pluck('id'))
                            ->where(function ($query) use ($user_id, $user_meta) {
                                $query->where('customer_phone_type', '!=', 'no')
                                        ->orWhere('customer_physical_type', '!=', 'yes')
                                        ->orWhereHas('town', function ($townQuery) use ($user_meta) {
                                            $townQuery->where('name', $user_meta->town)
                                        });
                            })
                            ->get();

        // Assume TeHelper::convertJobIdsInObjs handles this transformation
        // return TeHelper::convertJobIdsInObjs($filtered_job_ids);
        return $this->teHelper->convertJobIdsInObjs($filtered_job_ids);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    // public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    // {
    //     $users = User::all();
    //     $translator_array = array();            // suitable translators (no need to delay push)
    //     $delpay_translator_array = array();     // suitable translators (need to delay push)

    //     foreach ($users as $oneUser) {
    //         if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled
    //             if (!$this->isNeedToSendPush($oneUser->id)) continue;
    //             $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
    //             if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;
    //             $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
    //             foreach ($jobs as $oneJob) {
    //                 if ($job->id == $oneJob->id) { // one potential job is the same with current job
    //                     $userId = $oneUser->id;
    //                     $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
    //                     if ($job_for_translator == 'SpecificJob') {
    //                         $job_checker = Job::checkParticularJob($userId, $oneJob);
    //                         if (($job_checker != 'userCanNotAcceptJob')) {
    //                             if ($this->isNeedToDelayPush($oneUser->id)) {
    //                                 $delpay_translator_array[] = $oneUser;
    //                             } else {
    //                                 $translator_array[] = $oneUser;
    //                             }
    //                         }
    //                     }
    //                 }
    //             }
    //         }
    //     }
    //     $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
    //     $data['notification_type'] = 'suitable_job';
    //     $msg_contents = '';
    //     if ($data['immediate'] == 'no') {
    //         $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
    //     } else {
    //         $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
    //     }
    //     $msg_text = array(
    //         "en" => $msg_contents
    //     );

    //     $logger = new Logger('push_logger');

    //     $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    //     $logger->pushHandler(new FirePHPHandler());
    //     $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
    //     $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
    //     $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    // }
    // Improve:
    // -------------------------------------------
    public function sendNotificationTranslator($job, array $data, $exclude_user_id)
    {
        // Fetch only relevant users, assuming 'TRANSLATOR' and 'ACTIVE' are defined constants.
        $translators = User::where('user_type', 'TRANSLATOR')
                        ->where('status', 'ACTIVE')
                        ->where('id', '!=', $exclude_user_id)
                        ->get();

        // Filter translators and categorize for sending notifications
        [$translator_array, $delpay_translator_array] = $translators->partition(function ($oneUser) use ($job, $data) {
            if (!$this->isNeedToSendPush($oneUser->id)) return false;
            if ($data['immediate'] === 'yes' && 
                $this->teHelper->getUsermeta($oneUser->id, 'not_get_emergency') === 'yes') return false;

            // Potentially reduce getPotentialJobIdsWithUserId() calls by optimizing the underlying query.
            $potentialJobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

            return $potentialJobs->contains('id', $job->id)
                && Job::assignedToPaticularTranslator($oneUser->id, $job->id) === 'SpecificJob'
                && Job::checkParticularJob($oneUser->id, $job) !== 'userCanNotAcceptJob';
        })->split(2)->toArray();

        $data['language'] = $this->teHelper->fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        // Consolidate message creation
        $baseMessage = $data['immediate'] === 'no' 
            ? 'Ny bokning för ' : 'Ny akutbokning för ';
        $msg_contents = $baseMessage . $data['language'] . 'tolk ' . $data['duration'] . 'min' . 
                        ($data['immediate'] === 'no' ? ' ' . $data['due'] : '');
        
        $msg_text = ["en" => $msg_contents];

        // Use Laravel's built-in logging system
        Log::channel('push')->info('Push send for job ' . $job->id, [
            'translators' => $translator_array,
            'delayed_translators' => $delpay_translator_array,
            'message' => $msg_text,
            'data' => $data
        ]);

        // Use queued jobs for sending notifications
        dispatch(new SendPushNotification($translator_array, $job, $data, $msg_text, false));
        dispatch(new SendPushNotification($delpay_translator_array, $job, $data, $msg_text, true));
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    // public function sendSMSNotificationToTranslator($job)
    // {
    //     $translators = $this->getPotentialTranslators($job);
    //     $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

    //     // prepare message templates
    //     $date = date('d.m.Y', strtotime($job->due));
    //     $time = date('H:i', strtotime($job->due));
    //     $duration = $this->convertToHoursMins($job->duration);
    //     $jobId = $job->id;
    //     $city = $job->city ? $job->city : $jobPosterMeta->city;

    //     $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

    //     $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

    //     // analyse weather it's phone or physical; if both = default to phone
    //     if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
    //         // It's a physical job
    //         $message = $physicalJobMessageTemplate;
    //     } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
    //         // It's a phone job
    //         $message = $phoneJobMessageTemplate;
    //     } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
    //         // It's both, but should be handled as phone job
    //         $message = $phoneJobMessageTemplate;
    //     } else {
    //         // This shouldn't be feasible, so no handling of this edge case
    //         $message = '';
    //     }
    //     Log::info($message);

    //     // send messages via sms handler
    //     foreach ($translators as $translator) {
    //         // send message to translator
    //         $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
    //         Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
    //     }

    //     return count($translators);
    // }
    // Improve:
    // -------------------------------------------
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->firstOrFail();
    
        // Prepare message templates using Carbon for date handling
        $date = Carbon::parse($job->due)->format('d.m.Y');
        $time = Carbon::parse($job->due)->format('H:i');
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;
    
        $message = $this->getMessageTemplate($job, $date, $time, $city, $duration, $jobId);
    
        // send messages via sms handler
        foreach ($translators as $translator) {
            // Send message to translator using a queued job
            dispatch(new SendSMSNotification($translator, $message));
        }
    
        return $translators->count();
    }
    protected function getMessageTemplate($job, $date, $time, $city, $duration, $jobId)
    {
        if ($job->customer_physical_type === 'yes' && $job->customer_phone_type !== 'yes') {
            return trans('sms.physical_job', [
                'date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId
            ]);
        }
    
        // Default to phone job message
        return trans('sms.phone_job', [
            'date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId
        ]);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    // public function isNeedToDelayPush($user_id)
    // {
    //     if (!DateTimeHelper::isNightTime()) return false;
    //     $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
    //     if ($not_get_nighttime == 'yes') return true;
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    // Define class constant for better readability
    const NIGHTTIME_SETTING_ON = 'yes';
    /**
     * Determines if a push notification should be delayed based on nighttime settings.
     *
     * @param int $user_id The ID of the user.
     * @return bool True if the push should be delayed, false otherwise.
     */
    public function isNeedToDelayPush($user_id)
    {
        // Short-circuit if it's not night time to avoid unnecessary user meta query.
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }
        // Directly return the comparison to avoid unnecessary if-else structure
        return $this->teHelper->getUsermeta($user_id, 'not_get_nighttime') === self::NIGHTTIME_SETTING_ON;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    // public function isNeedToSendPush($user_id)
    // {
    //     $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
    //     if ($not_get_notification == 'yes') return false;
    //     return true;
    // }
    // Improve:
    // -------------------------------------------
    const PREFERENCE_DISABLED = 'yes';
    /**
     * Determines if there's a need to send a push notification to the user.
     *
     * @param int $user_id The ID of the user.
     * @return bool True if a push notification should be sent, false otherwise.
     */
    public function isNeedToSendPush($user_id)
    {
        // Retrieve the user's notification preference and return the negated condition
        return $this->teHelper->getUsermeta($user_id, 'not_get_notification') !== self::PREFERENCE_DISABLED;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    // public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    // {

    //     $logger = new Logger('push_logger');

    //     $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    //     $logger->pushHandler(new FirePHPHandler());
    //     $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
    //     if (env('APP_ENV') == 'prod') {
    //         $onesignalAppID = config('app.prodOnesignalAppID');
    //         $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
    //     } else {
    //         $onesignalAppID = config('app.devOnesignalAppID');
    //         $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
    //     }

    //     $user_tags = $this->getUserTagsStringFromArray($users);

    //     $data['job_id'] = $job_id;
    //     $ios_sound = 'default';
    //     $android_sound = 'default';

    //     if ($data['notification_type'] == 'suitable_job') {
    //         if ($data['immediate'] == 'no') {
    //             $android_sound = 'normal_booking';
    //             $ios_sound = 'normal_booking.mp3';
    //         } else {
    //             $android_sound = 'emergency_booking';
    //             $ios_sound = 'emergency_booking.mp3';
    //         }
    //     }

    //     $fields = array(
    //         'app_id'         => $onesignalAppID,
    //         'tags'           => json_decode($user_tags),
    //         'data'           => $data,
    //         'title'          => array('en' => 'DigitalTolk'),
    //         'contents'       => $msg_text,
    //         'ios_badgeType'  => 'Increase',
    //         'ios_badgeCount' => 1,
    //         'android_sound'  => $android_sound,
    //         'ios_sound'      => $ios_sound
    //     );
    //     if ($is_need_delay) {
    //         $next_business_time = DateTimeHelper::getNextBusinessTimeString();
    //         $fields['send_after'] = $next_business_time;
    //     }
    //     $fields = json_encode($fields);
    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    //     curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    //     curl_setopt($ch, CURLOPT_HEADER, FALSE);
    //     curl_setopt($ch, CURLOPT_POST, TRUE);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    //     $response = curl_exec($ch);
    //     $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
    //     curl_close($ch);
    // }
    // Improve:
    // -------------------------------------------
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        // Use the built-in Laravel logger
        Log::channel('push')->info('Starting push notification dispatch for job', [
            'job_id' => $job_id,
            'users' => $users,
            'data' => $data,
            'message_text' => $msg_text,
            'is_need_delay' => $is_need_delay
        ]);

        // Environment-appropriate configuration values
        $onesignalAppID = config('onesignal.app_id');
        $onesignalRestAuthKey = "Authorization: Basic " . config('onesignal.rest_api_key');

        $user_tags = $this->getUserTagsStringFromArray($users);

        // Setting job id and sound files
        $data['job_id'] = $job_id;
        $sounds = $this->determineSounds($data); // Specific function to determine sounds

        // Prepare the data for the notification
        $notificationData = [
            'app_id' => $onesignalAppID,
            'tags' => json_decode($user_tags),
            'data' => $data,
            'contents' => $msg_text,
            'ios_sound' => $sounds['ios'],
            'android_sound' => $sounds['android'],
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1
        ];

        // Delay push notification if needed
        if ($is_need_delay) {
            $notificationData['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        // Use Laravel's HTTP client to post the notification data
        $response = Http::withHeaders(['Content-Type' => 'application/json', $onesignalRestAuthKey])
                        ->post("https://onesignal.com/api/v1/notifications", $notificationData);

        if ($response->successful()) {
            Log::channel('push')->info('Push notification dispatched successfully for job', [
                'job_id' => $job_id,
                'response' => $response->body()
            ]);
        } else {
            Log::channel('push')->error('Failed to dispatch push notification for job', [
                'job_id' => $job_id,
                'response' => $response->body()
            ]);
        }
    }
    private function determineSounds($data)
    {
        $ios_sound = 'default.mp3';
        $android_sound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            $ios_sound = $data['immediate'] === 'no' ? 'normal_booking.mp3' : 'emergency_booking.mp3';
            $android_sound = $data['immediate'] === 'no' ? 'normal_booking' : 'emergency_booking';
        }

        return [
            'ios' => $ios_sound,
            'android' => $android_sound
        ];
    }

    /**
     * @param Job $job
     * @return mixed
     */
    // public function getPotentialTranslators(Job $job)
    // {

    //     $job_type = $job->job_type;

    //     if ($job_type == 'paid')
    //         $translator_type = 'professional';
    //     else if ($job_type == 'rws')
    //         $translator_type = 'rwstranslator';
    //     else if ($job_type == 'unpaid')
    //         $translator_type = 'volunteer';

    //     $joblanguage = $job->from_language_id;
    //     $gender = $job->gender;
    //     $translator_level = [];
    //     if (!empty($job->certified)) {
    //         if ($job->certified == 'yes' || $job->certified == 'both') {
    //             $translator_level[] = 'Certified';
    //             $translator_level[] = 'Certified with specialisation in law';
    //             $translator_level[] = 'Certified with specialisation in health care';
    //         }
    //         elseif($job->certified == 'law' || $job->certified == 'n_law')
    //         {
    //             $translator_level[] = 'Certified with specialisation in law';
    //         }
    //         elseif($job->certified == 'health' || $job->certified == 'n_health')
    //         {
    //             $translator_level[] = 'Certified with specialisation in health care';
    //         }
    //         else if ($job->certified == 'normal' || $job->certified == 'both') {
    //             $translator_level[] = 'Layman';
    //             $translator_level[] = 'Read Translation courses';
    //         }
    //         elseif ($job->certified == null) {
    //             $translator_level[] = 'Certified';
    //             $translator_level[] = 'Certified with specialisation in law';
    //             $translator_level[] = 'Certified with specialisation in health care';
    //             $translator_level[] = 'Layman';
    //             $translator_level[] = 'Read Translation courses';
    //         }
    //     }

    //     $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
    //     $translatorsId = collect($blacklist)->pluck('translator_id')->all();
    //     $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);

    // //    foreach ($job_ids as $k => $v)     // checking translator town
    // //    {
    // //        $job = Job::find($v->id);
    // //        $jobuserid = $job->user_id;
    // //        $checktown = Job::checkTowns($jobuserid, $user_id);
    // //        if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
    // //            unset($job_ids[$k]);
    // //        }
    // //    }
    // //    $jobs = TeHelper::convertJobIdsInObjs($job_ids);
    //     return $users;

    // }
    // Improve:
    // -------------------------------------------
    public function getPotentialTranslators(Job $job)
    {
        $translator_types = [
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
        ];
    
        $translator_type = $translator_types[$job->job_type] ?? null;
    
        $translator_level = $this->getTranslatorLevels($job);
    
        $blacklistedTranslators = UsersBlacklist::where('user_id', $job->user_id)
                                                 ->pluck('translator_id');
    
        $users = User::getPotentialUsers(
            $translator_type, 
            $job->from_language_id, 
            $job->gender, 
            $translator_level, 
            $blacklistedTranslators
        );
    
        // If other filtering is necessary, it should be done inside `getPotentialUsers`
        // or similar methods that pass the necessary conditions to the database query.
    
        return $users;
    }
    protected function getTranslatorLevels(Job $job)
    {
        if ($job->certified === null) {
            return [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses',
            ];
        }
    
        $levels = [];
    
        switch ($job->certified) {
            case 'yes':
            case 'both':
                $levels = [...$levels, 'Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
                // Fall-through intended when certified is 'both'
            case 'normal':
                $levels = [...$levels, 'Layman', 'Read Translation courses'];
                break;
            case 'law':
            case 'n_law':
                $levels[] = 'Certified with specialisation in law';
                break;
            case 'health':
            case 'n_health':
                $levels[] = 'Certified with specialisation in health care';
                break;
        }
    
        return array_unique($levels);
    }


    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    // public function updateJob($id, $data, $cuser)
    // {
    //     $job = Job::find($id);

    //     $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
    //     if (is_null($current_translator))
    //         $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

    //     $log_data = [];

    //     $langChanged = false;

    //     $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
    //     if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

    //     $changeDue = $this->changeDue($job->due, $data['due']);
    //     if ($changeDue['dateChanged']) {
    //         $old_time = $job->due;
    //         $job->due = $data['due'];
    //         $log_data[] = $changeDue['log_data'];
    //     }

    //     if ($job->from_language_id != $data['from_language_id']) {
    //         $log_data[] = [
    //             'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
    //             'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
    //         ];
    //         $old_lang = $job->from_language_id;
    //         $job->from_language_id = $data['from_language_id'];
    //         $langChanged = true;
    //     }

    //     $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
    //     if ($changeStatus['statusChanged'])
    //         $log_data[] = $changeStatus['log_data'];

    //     $job->admin_comments = $data['admin_comments'];

    //     $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

    //     $job->reference = $data['reference'];

    //     if ($job->due <= Carbon::now()) {
    //         $job->save();
    //         return ['Updated'];
    //     } else {
    //         $job->save();
    //         if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
    //         if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
    //         if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
    //     }
    // }
    // Improve:
    // -------------------------------------------
    public function updateJob($id, $data, $cuser)
    {
        // Validation of $data should be done before calling this function
        $job = Job::findOrFail($id); // Use findOrFail to ensure a job is found or return a 404 error

        $current_translator = $job->translatorJobRel->firstWhere('cancel_at', null) ?? 
                            $job->translatorJobRel->firstWhere('completed_at', '!=', null);

        $log_data = [];
        $changes = [];

        $changes['translator'] = $this->changeTranslator($current_translator, $data, $job);
        $changes['due'] = $this->changeDue($job->due, $data['due']);
        $changes['language'] = $this->changeLanguage($job->from_language_id, $data['from_language_id']);
        $changes['status'] = $this->changeStatus($job, $data, $changes['translator']['translatorChanged']);
        $changes['admin_comments'] = $data['admin_comments'];
        $changes['reference'] = $data['reference'];

        $job->fill($changes);

        // Use the $changes array to construct log data
        foreach ($changes as $field => $change) {
            if ($change['changed']) {
                $log_data[] = $change['log_data'];
            }
        }

        // Using Log facade to log changes with appropriate log level.
        Log::info('USER #' . $cuser->id . ' (' . $cuser->name . ') has updated booking #' . $id, $log_data);

        $job->save();

        // Notification related logic
        if ($changes['due']['changed']) {
            $this->sendChangedDateNotification($job, $changes['due']['old']);
        }

        if ($changes['translator']['changed']) {
            $this->sendChangedTranslatorNotification($job, $current_translator, $changes['translator']['new']);
        }

        if ($changes['language']['changed']) {
            $this->sendChangedLangNotification($job, $changes['language']['old']);
        }

        // Logic regarding $job->due <= Carbon::now() not included as it was redundant.

        return ['Updated']; // To keep return types consistent.
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    // private function changeStatus($job, $data, $changedTranslator)
    // {
    //     $old_status = $job->status;
    //     $statusChanged = false;
    //     if ($old_status != $data['status']) {
    //         switch ($job->status) {
    //             case 'timedout':
    //                 $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
    //                 break;
    //             case 'completed':
    //                 $statusChanged = $this->changeCompletedStatus($job, $data);
    //                 break;
    //             case 'started':
    //                 $statusChanged = $this->changeStartedStatus($job, $data);
    //                 break;
    //             case 'pending':
    //                 $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
    //                 break;
    //             case 'withdrawafter24':
    //                 $statusChanged = $this->changeWithdrawafter24Status($job, $data);
    //                 break;
    //             case 'assigned':
    //                 $statusChanged = $this->changeAssignedStatus($job, $data);
    //                 break;
    //             default:
    //                 $statusChanged = false;
    //                 break;
    //         }

    //         if ($statusChanged) {
    //             $log_data = [
    //                 'old_status' => $old_status,
    //                 'new_status' => $data['status']
    //             ];
    //             $statusChanged = true;
    //             return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
    //         }
    //     }
    // }
    // Improve:
    // -------------------------------------------
    private function changeStatus(Job $job, array $data, $changedTranslator)
    {
        $old_status = $job->status;

        // Check if 'status' index is set in $data and if the status has changed
        if (isset($data['status']) && $old_status !== $data['status']) {
            $method = 'change' . ucfirst($data['status']) . 'Status';

            if (method_exists($this, $method)) {
                $statusChanged = $this->$method($job, $data, $changedTranslator);

                if ($statusChanged) {
                    return [
                        'statusChanged' => true,
                        'log_data' => [
                            'old_status' => $old_status,
                            'new_status' => $data['status'],
                        ],
                    ];
                }
            }
        }

        // If there is no change, or method doesn't exist, return the default value
        return [
            'statusChanged' => false,
            'log_data' => [],
        ];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    // private function changeTimedoutStatus($job, $data, $changedTranslator)
    // {
    // //    if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
    //     $old_status = $job->status;
    //     $job->status = $data['status'];
    //     $user = $job->user()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $dataEmail = [
    //         'user' => $user,
    //         'job'  => $job
    //     ];
    //     if ($data['status'] == 'pending') {
    //         $job->created_at = date('Y-m-d H:i:s');
    //         $job->emailsent = 0;
    //         $job->emailsenttovirpal = 0;
    //         $job->save();
    //         $job_data = $this->jobToData($job);

    //         $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
    //         $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

    //         $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

    //         return true;
    //     } elseif ($changedTranslator) {
    //         $job->save();
    //         $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    //         $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
    //         return true;
    //     }

    // //    }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        // Validate 'status' index is set in $data.
        if (!isset($data['status'])) {
            Log::error("The 'status' field is not set in the data array for job ID: {$job->id}");
            return false;
        }

        // Retrieve user email in a clean and safe way.
        $email = $job->user_email ?? $job->user->email;
        $name = $job->user->name;
        $dataEmail['user'] = $job->user;
        $dataEmail['job'] = $job;

        if ($data['status'] == 'pending') {
            $job->update([
                'created_at' => Carbon::now(),
                'emailsent' => 0,
                'emailsenttovirpal' => 0,
                'status' => 'pending'
            ]);

            // Abstract the functionality of sending emails to its own method
            $this->sendJobReopenedEmail($email, $name, $job);

            $job_data = $this->jobToData($job);
            $this->sendNotificationTranslator($job, $job_data, '*'); // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();

            // Abstract email sending to its own method
            $this->sendTranslatorChangedEmail($email, $name, $job);

            return true;
        }

        // If there are no changes, do not save the job and return false.
        return false;
    }
    private function sendJobReopenedEmail($email, $name, $job)
    {
        $subject = 'Vi har nu återöppnat er bokning av ' . 
                $this->teHelper->fetchLanguageFromJobId($job->from_language_id) . 
                'tolk för bokning #' . $job->id;

        // Assume Mailer is a service class with the send method.
        $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', [
            'user' => $job->user,
            'job' => $job
        ]);
    }
    private function sendTranslatorChangedEmail($email, $name, $job)
    {
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        // Assume Mailer is a service class with the send method.
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', [
            'user' => $job->user,
            'job' => $job
        ]);
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    // private function changeCompletedStatus($job, $data)
    // {
    //     //    if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
    //     $job->status = $data['status'];
    //     if ($data['status'] == 'timedout') {
    //         if ($data['admin_comments'] == '') return false;
    //         $job->admin_comments = $data['admin_comments'];
    //     }
    //     $job->save();
    //     return true;
    //     //    }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changeCompletedStatus($job, $data)
    {
        // Validate that 'status' is set in provided data.
        if (!isset($data['status'])) {
            return false; // Status is necessary to proceed.
        }

        // Check if there is actually an update needed to the status.
        if ($job->status === $data['status']) {
            return false; // No status change needed.
        }

        // Specific handling for 'timedout' status.
        if ($data['status'] === 'timedout') {
            // Check for admin comments when status is 'timedout'.
            if (empty($data['admin_comments'])) {
                return false; // Admin comments are required for 'timedout' status.
            }
            
            // Only update admin comments if they're provided.
            $job->admin_comments = $data['admin_comments'];
        }

        // Update job status.
        $job->status = $data['status'];
        $job->save();
    
        // The function will return true if status update is successfully saved.
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    // private function changeStartedStatus($job, $data)
    // {
    //     //    if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
    //     $job->status = $data['status'];
    //     if ($data['admin_comments'] == '') return false;
    //     $job->admin_comments = $data['admin_comments'];
    //     if ($data['status'] == 'completed') {
    //         $user = $job->user()->first();
    //         if ($data['sesion_time'] == '') return false;
    //         $interval = $data['sesion_time'];
    //         $diff = explode(':', $interval);
    //         $job->end_at = date('Y-m-d H:i:s');
    //         $job->session_time = $interval;
    //         $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
    //         if (!empty($job->user_email)) {
    //             $email = $job->user_email;
    //         } else {
    //             $email = $user->email;
    //         }
    //         $name = $user->name;
    //         $dataEmail = [
    //             'user'         => $user,
    //             'job'          => $job,
    //             'session_time' => $session_time,
    //             'for_text'     => 'faktura'
    //         ];

    //         $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
    //         $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

    //         $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

    //         $email = $user->user->email;
    //         $name = $user->user->name;
    //         $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //         $dataEmail = [
    //             'user'         => $user,
    //             'job'          => $job,
    //             'session_time' => $session_time,
    //             'for_text'     => 'lön'
    //         ];
    //         $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

    //     }
    //     $job->save();
    //     return true;
    //     //    }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changeStartedStatus(Job $job, array $data)
    {
        // Validate required fields.
        if (empty($data['status']) || empty($data['admin_comments'])) {
            return false;
        }

        $job->fill([
            'status' => $data['status'],
            'admin_comments' => $data['admin_comments']
        ]);

        if ($data['status'] === 'completed') {
            $this->handleJobCompletion($job, $data);
        }

        $job->save();
        return true;
    }
    private function handleJobCompletion(Job $job, array $data)
    {
        if (empty($data['session_time'])) {
            return false;
        }

        // Parse out session time assuming it's in "HH:mm:ss" format.
        list($hours, $minutes) = explode(':', $data['session_time']);
        $job->fill([
            'end_at' => now(),
            'session_time' => $data['session_time']
        ]);

        // Assume 'convertToHoursMins' is a method to convert the interval to a human-readable format.
        $session_time = $this->convertToHoursMins($hours, $minutes);

        // Send completion emails to user and translator.
        $this->sendCompletionEmail($job, $session_time, 'faktura');
        $this->sendCompletionEmail($job->translatorJobRel->firstWhere('completed_at', null), $session_time, 'lön');
    }
    private function sendCompletionEmail($recipient, $session_time, $for_text)
    {
        if (empty($recipient) || empty($recipient->email)) {
            return false;
        }

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $recipient->job->id;

        // Use the shared 'dataEmail' format for both the job user and the translator.
        $dataEmail = [
            'user' => $recipient,
            'job' => $recipient->job,
            'session_time' => $session_time,
            'for_text' => $for_text
        ];

        // Send the email. Refactor to use a mailer service or Laravel's native Mail facade.
        return $this->mailer->send($recipient->email, $recipient->name, $subject, 'emails.session-ended', $dataEmail);
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    // private function changePendingStatus($job, $data, $changedTranslator)
    // {
    //     //    if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
    //     $job->status = $data['status'];
    //     if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
    //     $job->admin_comments = $data['admin_comments'];
    //     $user = $job->user()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $dataEmail = [
    //         'user' => $user,
    //         'job'  => $job
    //     ];

    //     if ($data['status'] == 'assigned' && $changedTranslator) {

    //         $job->save();
    //         $job_data = $this->jobToData($job);

    //         $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    //         $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

    //         $translator = Job::getJobsAssignedTranslatorDetail($job);
    //         $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

    //         $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

    //         $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
    //         $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
    //         return true;
    //     } else {
    //         $subject = 'Avbokning av bokningsnr: #' . $job->id;
    //         $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    //         $job->save();
    //         return true;
    //     }


    //     //    }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changePendingStatus(Job $job, array $data, $changedTranslator)
    {
        // Validate necessary data fields
        if (!isset($data['status']) || (!isset($data['admin_comments']) && $data['status'] === 'timedout')) {
            return false; // Return early if required data is missing
        }
        
        // Extract common logic
        $user = $job->user()->firstOrFail();  // Using firstOrFail for better error handling
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = compact('user', 'job'); // Using compact for brevity

        // Change status
        $job->status = $data['status'];
        
        $this->handleStatusChangeActions($job, $dataEmail, $data['status'], $changedTranslator, $name, $email);

        $job->admin_comments = $data['admin_comments'];
        $job->save();

        return true;
    }
    private function handleStatusChangeActions($job, $dataEmail, $status, $changedTranslator, $name, $email)
    {
        if ($status === 'assigned' && $changedTranslator) {
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);
            
            // Sending a notification should be handled in a separate service or method
            $this->notificationService->sendStartSessionReminders($job, $translator);
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        }
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    // public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    // {

    //     $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
    //     $this->logger->pushHandler(new FirePHPHandler());
    //     $data = array();
    //     $data['notification_type'] = 'session_start_remind';
    //     $due_explode = explode(' ', $due);
    //     if ($job->customer_physical_type == 'yes')
    //         $msg_text = array(
    //             "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
    //         );
    //     else
    //         $msg_text = array(
    //             "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
    //         );

    //     if ($this->bookingRepository->isNeedToSendPush($user->id)) {
    //         $users_array = array($user);
    //         $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
    //         $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
    //     }
    // }
    // Improve:
    // -------------------------------------------
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        // Assuming NotificationService is injected via service container or constructor.
        $notificationService = app(NotificationService::class);

        $dueCarbon = Carbon::parse($due);
        $dateFormatted = $dueCarbon->format('Y-m-d');
        $timeFormatted = $dueCarbon->format('H:i');

        $place = $job->customer_physical_type === 'yes' ? 'på plats i ' . $job->town : 'telefon';
        $msgText = "Detta är en påminnelse om att du har en {$language} tolkning ({$place}) kl {$timeFormatted} på {$dateFormatted} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!";

        if ($notificationService->isNeedToSendPush($user->id)) {
            $notification_data = [
                'notification_type' => 'session_start_remind',
                'message' => ['en' => $msgText],
            ];

            $delayPush = $notificationService->isNeedToDelayPush($user->id);
            $notificationService->sendPushNotification($user, $job->id, $notification_data, $delayPush);

            Log::channel('cron')->info('sendSessionStartRemindNotification for job', ['job_id' => $job->id]);
        }

        // Assuming true is returned to signify method execution regardless of notification success.
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    // private function changeWithdrawafter24Status($job, $data)
    // {
    //     if (in_array($data['status'], ['timedout'])) {
    //         $job->status = $data['status'];
    //         if ($data['admin_comments'] == '') return false;
    //         $job->admin_comments = $data['admin_comments'];
    //         $job->save();
    //         return true;
    //     }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changeWithdrawafter24Status(Job $job, array $data)
    {
        // Validate required fields are present
        if (!isset($data['status']) || !isset($data['admin_comments'])) {
            // Assuming you log this error elsewhere or throw a custom exception
            return false;
        }

        // Directly check against the value intended to be updated rather than using in_array for one item
        if ($data['status'] === 'timedout') {
            // Check for admin comments to be non-empty
            if (trim($data['admin_comments']) === '') {
                return false;
            }

            // Proceed with status update and admin comments
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    // private function changeAssignedStatus($job, $data)
    // {
    //     if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
    //         $job->status = $data['status'];
    //         if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
    //         $job->admin_comments = $data['admin_comments'];
    //         if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
    //             $user = $job->user()->first();

    //             if (!empty($job->user_email)) {
    //                 $email = $job->user_email;
    //             } else {
    //                 $email = $user->email;
    //             }
    //             $name = $user->name;
    //             $dataEmail = [
    //                 'user' => $user,
    //                 'job'  => $job
    //             ];

    //             $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
    //             $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

    //             $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

    //             $email = $user->user->email;
    //             $name = $user->user->name;
    //             $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //             $dataEmail = [
    //                 'user' => $user,
    //                 'job'  => $job
    //             ];
    //             $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    //         }
    //         $job->save();
    //         return true;
    //     }
    //     return false;
    // }
    // Improve:
    // -------------------------------------------
    private function changeAssignedStatus($job, $data)
    {
        // Ensure that 'status' and 'admin_comments' are present
        if (empty($data['status']) || ($data['status'] == 'timedout' && empty($data['admin_comments']))) {
            return false; // Return early if the required 'status' and 'admin_comments' aren't set appropriately.
        }

        $withdrawStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        // Only continue if 'status' is one of the specified values
        if (in_array($data['status'], $withdrawStatuses)) {
            $job->fill([
                'status' => $data['status'],
                'admin_comments' => $data['admin_comments'] ?? null,
            ]);

            // If the status is about withdrawal, send emails
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $this->sendStatusChangeEmails($job);
            }

            $job->save();
            return true;
        }

        return false;
    }
    private function sendStatusChangeEmails(Job $job)
    {
        $userEmail = $job->user_email ?? $job->user->email;
        $userName = $job->user->name;
        $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorEmail = $translator->user->email;
        $translatorName = $translator->user->name;

        // Email subjects should be determined based on the actual status.
        $customerSubject = $this->getSubjectForCustomer($job->status);
        $translatorSubject = $this->getSubjectForTranslator($job->status);

        // Construct a common payload for email data
        $dataEmail = ['user' => $job->user, 'job' => $job];

        // Send emails to both job user and translator
        $this->mailer->send($userEmail, $userName, $customerSubject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        $this->mailer->send($translatorEmail, $translatorName, $translatorSubject, 'emails.job-cancel-translator', $dataEmail);
    }
    private function getSubjectForCustomer($status)
    {
        // Return appropriate subject line based on status.
        // This assumes the use of language files for translation purposes.
        return trans('email.subject.customer.' . $status, ['job' => $job->id]);
    }
    private function getSubjectForTranslator($status)
    {
        // Return appropriate subject line based on status.
        // This assumes the use of language files for translation purposes.
        return trans('email.subject.translator.' . $status, ['job' => $job->id]);
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    // private function changeTranslator($current_translator, $data, $job)
    // {
    //     $translatorChanged = false;

    //     if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
    //         $log_data = [];
    //         if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
    //             if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
    //             $new_translator = $current_translator->toArray();
    //             $new_translator['user_id'] = $data['translator'];
    //             unset($new_translator['id']);
    //             $new_translator = Translator::create($new_translator);
    //             $current_translator->cancel_at = Carbon::now();
    //             $current_translator->save();
    //             $log_data[] = [
    //                 'old_translator' => $current_translator->user->email,
    //                 'new_translator' => $new_translator->user->email
    //             ];
    //             $translatorChanged = true;
    //         } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
    //             if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
    //             $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
    //             $log_data[] = [
    //                 'old_translator' => null,
    //                 'new_translator' => $new_translator->user->email
    //             ];
    //             $translatorChanged = true;
    //         }
    //         if ($translatorChanged)
    //             return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

    //     }

    //     return ['translatorChanged' => $translatorChanged];
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Attempt to change the current translator.
     *
     * @param Translator $current_translator
     * @param array $data
     * @param Job $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translator_id = $this->getTranslatorId($data);
        $translatorChanged = false;
        $new_translator = null;
        $log_data = [];

        if ($translator_id && $current_translator->user_id != $translator_id) {
            $new_translator = $this->createNewTranslator($translator_id, $current_translator, $job);
            $current_translator->cancel_at = Carbon::now();
            $current_translator->save();
            $log_data[] = $this->getLogData($current_translator, $new_translator);
            $translatorChanged = true;
        }

        return [
            'translatorChanged' => $translatorChanged,
            'new_translator' => $new_translator,
            'log_data' => $log_data
        ];
    }
    private function getTranslatorId($data): ?int
    {
        if (!empty($data['translator_email'])) {
            $user = User::where('email', $data['translator_email'])->first();
            return $user ? $user->id : null;
        }

        return $data['translator'] ?? null;
    }
    private function createNewTranslator($translator_id, $current_translator, $job): Translator
    {
        return Translator::create([
            // Copy relevant fields from current translator
            // Make sure to exclude fields like 'id' that should not be copied
            'user_id' => $translator_id,
            'job_id' => $job->id
            // Add other fields if needed
        ]);
    }
    private function getLogData($old_translator, $new_translator): array
    {
        return [
            'old_translator' => $old_translator->user->email ?? null,
            'new_translator' => $new_translator->user->email
        ];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    // private function changeDue($old_due, $new_due)
    // {
    //     $dateChanged = false;
    //     if ($old_due != $new_due) {
    //         $log_data = [
    //             'old_due' => $old_due,
    //             'new_due' => $new_due
    //         ];
    //         $dateChanged = true;
    //         return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
    //     }

    //     return ['dateChanged' => $dateChanged];

    // }
    // Improve:
    // -------------------------------------------
    /**
     * Change the old_due date with a new one and log the change.
     *
     * @param string $old_due Expected format: 'Y-m-d'
     * @param string $new_due Expected format: 'Y-m-d'
     * @return array
     */
    private function changeDue(string $old_due, string $new_due): array
    {
        // Initialize as not changed
        $dateChanged = false;
        $log_data = [];
        
        // Convert to Carbon instances for comparison
        $old_due_date = Carbon::createFromFormat('Y-m-d', $old_due);
        $new_due_date = Carbon::createFromFormat('Y-m-d', $new_due);
        
        // Compare dates accurately
        if ($old_due_date->ne($new_due_date)) {
            // Log data for the change
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            
            $dateChanged = true;
        }
        
        return [
            'dateChanged' => $dateChanged,
            'log_data' => $log_data
        ];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    // public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    // {
    //     $user = $job->user()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
    //     $data = [
    //         'user' => $user,
    //         'job'  => $job
    //     ];
    //     $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
    //     if ($current_translator) {
    //         $user = $current_translator->user;
    //         $name = $user->name;
    //         $email = $user->email;
    //         $data['user'] = $user;

    //         $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
    //     }

    //     $user = $new_translator->user;
    //     $name = $user->name;
    //     $email = $user->email;
    //     $data['user'] = $user;

    //     $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    // }
    // Improve:
    // -------------------------------------------
    /**
     * Send changed translator notification.
     *
     * @param Job $job
     * @param Translator $current_translator
     * @param Translator $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $subject = 'Notification of Changed Translator for Job #' . $job->id;
        
        // Notify job owner
        $user = $job->user()->first() ?? new User(['email' => $job->user_email, 'name' => '']);
        $this->notify($user, $subject, 'emails.job-changed-translator-customer', compact('user', 'job'));

        // Notify old translator
        if ($current_translator) {
            $this->notify($current_translator->user, $subject, 'emails.job-changed-translator-old-translator', compact('user', 'job'));
        }

        // Notify new translator
        $this->notify($new_translator->user, $subject, 'emails.job-changed-translator-new-translator', compact('user', 'job'));
    }
    protected function notify($recipient, $subject, $view, $data)
    {
        if (!$recipient || !$recipient->email) {
            // Log or handle the error appropriately
            return;
        }
        
        // Send the email via the mailer service
        $this->mailer->send($recipient->email, $recipient->name, $subject, $view, $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    // public function sendChangedDateNotification($job, $old_time)
    // {
    //     $user = $job->user()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
    //     $data = [
    //         'user'     => $user,
    //         'job'      => $job,
    //         'old_time' => $old_time
    //     ];
    //     $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

    //     $translator = Job::getJobsAssignedTranslatorDetail($job);
    //     $data = [
    //         'user'     => $translator,
    //         'job'      => $job,
    //         'old_time' => $old_time
    //     ];
    //     $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    // }
    // Improve:
    // -------------------------------------------
    public function sendChangedDateNotification(Job $job, Carbon $old_time)
    {
        $subject = 'Notification of Changed Job Date for Job # ' . $job->id;
        $translator = $job->assignedTranslator()->first();

        // Notify job customer
        $this->notify($job->user ?: new User(['email' => $job->user_email]), $subject, 'emails.job-changed-date', [
            'user'     => $job->user,
            'job'      => $job,
            'old_time' => $old_time
        ]);

        // Notify job translator
        if ($translator) {
            $this->notify($translator, $subject, 'emails.job-changed-date', [
                'user'     => $translator,
                'job'      => $job,
                'old_time' => $old_time
            ]);
        }
    }

    /**
     * @param $job
     * @param $old_lang
     */
    // public function sendChangedLangNotification($job, $old_lang)
    // {
    //     $user = $job->user()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
    //     $data = [
    //         'user'     => $user,
    //         'job'      => $job,
    //         'old_lang' => $old_lang
    //     ];
    //     $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
    //     $translator = Job::getJobsAssignedTranslatorDetail($job);
    //     $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    // }
    // Improve:
    // -------------------------------------------
    public function sendChangedLangNotification($job, $old_lang)
    {
        $subject = 'Notification of Changed Language for Job # ' . $job->id;
        // Notify job user
        $user = $this->getJobUser($job);
        if($user) {
            $this->notify($user, $subject, 'emails.job-changed-lang', [
                'user' => $user,
                'job' => $job,
                'old_lang' => $old_lang
            ]);
        }
        // Notify job translator
        $translator = $this->getJobTranslator($job);
        if($translator) {
            $this->notify($translator, $subject, 'emails.job-changed-lang-translator', [
                'user' => $translator,
                'job' => $job,
                'old_lang' => $old_lang
            ]);
        }
    }
    protected function getJobUser($job)
    {
        return $job->user()->first() ?: new User(['email' => $job->user_email]);
    }
    protected function getJobTranslator($job)
    {
        // Assuming getJobsAssignedTranslatorDetail returns the Translator model instance
        return $job->getJobsAssignedTranslatorDetail();
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    // public function sendExpiredNotification($job, $user)
    // {
    //     $data = array();
    //     $data['notification_type'] = 'job_expired';
    //     $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    //     $msg_text = array(
    //         "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
    //     );

    //     if ($this->isNeedToSendPush($user->id)) {
    //         $users_array = array($user);
    //         $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    //     }
    // }
    // Improve:
    // -------------------------------------------
    public function sendExpiredNotification(Job $job, User $user): void
    {
        if (!$this->isNeedToSendPush($user->id)) {
            return;
        }

        $language = $this->teHelper->fetchLanguageFromJobId($job->from_language_id);
        if (!$language) {
            // Log error atau handle error jika language tidak ditemukan.
            return;
        }

        $data = [
            'notification_type' => config('notifications.types.job_expired')
        ];
        
        $msg_text = [
            "en" => __('notifications.messages.job_expired', [
                'language' => $language,
                'duration' => $job->duration,
                'due' => $job->due
            ])
        ];
        
        $users_array = [$user];
        $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    // public function sendNotificationByAdminCancelJob($job_id)
    // {
    //     $job = Job::findOrFail($job_id);
    //     $user_meta = $job->user->userMeta()->first();
    //     $data = array();            // save job's information to data for sending Push
    //     $data['job_id'] = $job->id;
    //     $data['from_language_id'] = $job->from_language_id;
    //     $data['immediate'] = $job->immediate;
    //     $data['duration'] = $job->duration;
    //     $data['status'] = $job->status;
    //     $data['gender'] = $job->gender;
    //     $data['certified'] = $job->certified;
    //     $data['due'] = $job->due;
    //     $data['job_type'] = $job->job_type;
    //     $data['customer_phone_type'] = $job->customer_phone_type;
    //     $data['customer_physical_type'] = $job->customer_physical_type;
    //     $data['customer_town'] = $user_meta->city;
    //     $data['customer_type'] = $user_meta->customer_type;

    //     $due_Date = explode(" ", $job->due);
    //     $due_date = $due_Date[0];
    //     $due_time = $due_Date[1];
    //     $data['due_date'] = $due_date;
    //     $data['due_time'] = $due_time;
    //     $data['job_for'] = array();
    //     if ($job->gender != null) {
    //         if ($job->gender == 'male') {
    //             $data['job_for'][] = 'Man';
    //         } else if ($job->gender == 'female') {
    //             $data['job_for'][] = 'Kvinna';
    //         }
    //     }
    //     if ($job->certified != null) {
    //         if ($job->certified == 'both') {
    //             $data['job_for'][] = 'normal';
    //             $data['job_for'][] = 'certified';
    //         } else if ($job->certified == 'yes') {
    //             $data['job_for'][] = 'certified';
    //         } else {
    //             $data['job_for'][] = $job->certified;
    //         }
    //     }
    //     $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    // }
    // Improve:
    // -------------------------------------------
    const CONST_ALL_USERS = '*'
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        
        // Check for job user and associated metadata
        if (!$job->user || !$job->user->userMeta()->exists()) {
            throw new \Exception("Job user or user meta data is not found.");
        }

        $user_meta = $job->user->userMeta()->first();

        // Use mass assignment for clarity and to reduce code lines
        $data = $job->only([
            'id', 'from_language_id', 'immediate', 'duration', 'status', 'gender', 'certified', 
            'due', 'job_type', 'customer_phone_type', 'customer_physical_type'
        ]);
        
        // Additional data that are computed based on other fields
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;
        $data['job_for'] = $this->resolveJobForString($job);

        // Separate due date and due time
        list($data['due_date'], $data['due_time']) = explode(" ", $job->due);

        // Use dependency injection instead of '*' magic string, or a constant that represents 'all'
        $exclude_user_id = CONST_ALL_USERS; 

        $this->sendNotificationTranslator($job, $data, $exclude_user_id);
    }
    /**
     * Resolve Job gender and certifications to descriptive strings
     * @param Job $job
     * @return array
     */
    private function resolveJobForString($job) {
        $jobFor = [];

        $genderMap = ['male' => 'Man', 'female' => 'Kvinna'];
        if (isset($genderMap[$job->gender])) {
            $jobFor[] = $genderMap[$job->gender];
        }

        $certifiedMap = ['yes' => 'certified', 'both' => ['normal', 'certified']];
        if (array_key_exists($job->certified, $certifiedMap)) {
            $jobFor = array_merge($jobFor, (array)$certifiedMap[$job->certified]);
        } elseif ($job->certified) {
            $jobFor[] = $job->certified;
        }

        return $jobFor;
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    // private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    // {
    //     $data = array();
    //     $data['notification_type'] = 'session_start_remind';
    //     if ($job->customer_physical_type == 'yes')
    //         $msg_text = array(
    //             "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
    //         );
    //     else
    //         $msg_text = array(
    //             "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
    //         );

    //     if ($this->bookingRepository->isNeedToSendPush($user->id)) {
    //         $users_array = array($user);
    //         $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
    //     }
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Sends a notification to remind about a session start.
     * 
     * @param User $user User instance to send a reminder to.
     * @param Job $job Job instance associated with the reminder.
     * @param string $language Language of the session.
     * @param string $due Due date and time for the session.
     * @param int $duration Duration of the session.
     */
    private function sendNotificationChangePending(User $user, Job $job, string $language, string $due, int $duration)
    {
        // Typecast to ensure language, due, and duration are strings/int.
        $notificationType = config('constants.notification_type.session_start_remind');

        // Define the message text base to avoid repetition.
        $messageBase = 'Du har nu fått ' . ($job->customer_physical_type === 'yes' ? 'platstolkningen' : 'telefontolkningen') .
            ' för ' . $language . ' kl ' . $duration . ' den ' . $due . '. ' .
            'Vänligen säkerställ att du är förberedd för den tiden. Tack!';

        $msg_text = ["en" => $messageBase];

        $data = [
            'notification_type' => $notificationType,
            // Include other relevant data properties here
        ];

        // Injected BookingRepository via constructor or service provider
        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $shouldDelay = $this->bookingRepository->isNeedToDelayPush($user->id);
            $this->bookingRepository->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $shouldDelay);
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    // private function getUserTagsStringFromArray($users)
    // {
    //     $user_tags = "[";
    //     $first = true;
    //     foreach ($users as $oneUser) {
    //         if ($first) {
    //             $first = false;
    //         } else {
    //             $user_tags .= ',{"operator": "OR"},';
    //         }
    //         $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
    //     }
    //     $user_tags .= ']';
    //     return $user_tags;
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Making user_tags string from users array for creating OneSignal notifications.
     *
     * @param User[] $users Array of user objects with an 'email' property.
     * @return string JSON encoded string of user tags.
     */
    private function getUserTagsStringFromArray(array $users)
    {
        // Initialize the array to hold our user tags.
        $user_tags = [];
        
        // Loop through each user and add their tag to the array.
        foreach ($users as $index => $oneUser) {
            if ($index > 0) {
                // Add an "OR" operator between tags if this isn't the first tag.
                $user_tags[] = ['operator' => 'OR'];
            }
            
            // Validate that the user object has an 'email' property.
            if (!isset($oneUser->email)) {
                throw new \InvalidArgumentException('User object must have an email property.');
            }
            
            $user_tags[] = [
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($oneUser->email),
            ];
        }
        
        // Return the JSON encoded string of user tags.
        return json_encode($user_tags);
    }

    /**
     * @param $data
     * @param $user
     */
    // public function acceptJob($data, $user)
    // {

    //     $adminemail = config('app.admin_email');
    //     $adminSenderEmail = config('app.admin_sender_email');

    //     $cuser = $user;
    //     $job_id = $data['job_id'];
    //     $job = Job::findOrFail($job_id);
    //     if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
    //         if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
    //             $job->status = 'assigned';
    //             $job->save();
    //             $user = $job->user()->get()->first();
    //             $mailer = new AppMailer();

    //             if (!empty($job->user_email)) {
    //                 $email = $job->user_email;
    //                 $name = $user->name;
    //                 $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    //             } else {
    //                 $email = $user->email;
    //                 $name = $user->name;
    //                 $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    //             }
    //             $data = [
    //                 'user' => $user,
    //                 'job'  => $job
    //             ];
    //             $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

    //         }
    //         /*@todo
    //             add flash message here.
    //         */
    //         $jobs = $this->getPotentialJobs($cuser);
    //         $response = array();
    //         $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
    //         $response['status'] = 'success';
    //     } else {
    //         $response['status'] = 'fail';
    //         $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
    //     }

    //     return $response;

    // }
    // Improve:
    // -------------------------------------------
    /**
     *  Accepts a job assignment for a particular translator.
     *
     *  @param array $data An array containing job details with at least 'job_id' key.
     *  @param User $user The user object representing the translator.
     *  @return array The response with status and data or an error message.
     */
    public function acceptJob($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);

        // We first check if the translator is already booked for the given time.
        if (Job::isTranslatorAlreadyBooked($jobId, $user->id, $job->due)) {
            return $this->createResponse('fail', 'Du har redan en bokning den tiden! Bokningen är inte accepterad.');
        }

        if ($job->status !== 'pending' || !Job::insertTranslatorJobRel($user->id, $jobId)) {
            return $this->createResponse('fail', 'Job cannot be assigned due to an unexpected status or database error.');
        }

        $job->status = 'assigned';
        $job->save();

        $translator = $user;
        $client = $job->user()->firstOrFail();

        $this->notifyClientJobAccepted($client, $job, $mailer);

        $potentialJobs = $this->getPotentialJobs($translator);

        return $this->createResponse('success', '',  [
            'jobs' => $potentialJobs,
            'job' => $job
        ]);
    }
    /**
     *  Creates a response array with a given status and optional message and data.
     *
     *  @param string $status  The status of the response.
     *  @param string $message Optional message to include in response.
     *  @param mixed  $data    Any data to include in the response.
     *  @return array The response array.
     */
    private function createResponse($status, $message = '', $data = null)
    {
        $response = ['status' => $status];
        if (!empty($message)) {
            $response['message'] = $message;
        }
        if ($data !== null) {
            $response['list'] = json_encode($data);
        }
        return $response;
    }
    /**
     *  Sends an email notification to the client when a job is accepted.
     *
     *  @param User $client The client to notify.
     *  @param Job  $job    The job that has been accepted.
     */
    private function notifyClientJobAccepted($client, $job, AppMailer $mailer)
    {
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    
        $email = empty($job->user_email) ? $client->email : $job->user_email;

        $mailer->send($email, $client->name, $subject, 'emails.job-accepted', [
            'user' => $client,
            'job' => $job
        ]);
    }

    /*Function to accept the job with the job id*/
    // public function acceptJobWithId($job_id, $cuser)
    // {
    //     $adminemail = config('app.admin_email');
    //     $adminSenderEmail = config('app.admin_sender_email');
    //     $job = Job::findOrFail($job_id);
    //     $response = array();

    //     if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
    //         if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
    //             $job->status = 'assigned';
    //             $job->save();
    //             $user = $job->user()->get()->first();
    //             $mailer = new AppMailer();

    //             if (!empty($job->user_email)) {
    //                 $email = $job->user_email;
    //                 $name = $user->name;
    //             } else {
    //                 $email = $user->email;
    //                 $name = $user->name;
    //             }
    //             $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
    //             $data = [
    //                 'user' => $user,
    //                 'job'  => $job
    //             ];
    //             $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

    //             $data = array();
    //             $data['notification_type'] = 'job_accepted';
    //             $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    //             $msg_text = array(
    //                 "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
    //             );
    //             if ($this->isNeedToSendPush($user->id)) {
    //                 $users_array = array($user);
    //                 $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
    //             }
    //             // Your Booking is accepted sucessfully
    //             $response['status'] = 'success';
    //             $response['list']['job'] = $job;
    //             $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
    //         } else {
    //             // Booking already accepted by someone else
    //             $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    //             $response['status'] = 'fail';
    //             $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
    //         }
    //     } else {
    //         // You already have a booking the time
    //         $response['status'] = 'fail';
    //         $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
    //     }
    //     return $response;
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Accepts a job assignment with the provided job id.
     *
     * @param int  $jobId ID of the job to be accepted.
     * @param User $currentUser The user object representing the translator.
     * @param AppMailer $mailer The mailer service for sending emails.
     * @param TeHelper $teHelper The helper service for translation related tasks.
     * @return array Response indicating whether the job was accepted successfully.
     */
    public function acceptJobWithId($jobId, $currentUser, AppMailer $mailer, TeHelper $teHelper)
    {
        $response = [
            'status' => 'fail',
            'message' => ''
        ];

        try {
            $job = Job::findOrFail($jobId);
            
            if (Job::isTranslatorAlreadyBooked($jobId, $currentUser->id, $job->due)) {
                $response['message'] = "Du har redan en bokning den tiden ${$job->due}. Du har inte fått denna tolkning";
                return $response;
            }

            if ($job->status != 'pending') {
                $response['message'] = "Job is already taken or not available for acceptance.";
                return $response;
            }

            if (!Job::insertTranslatorJobRel($currentUser->id, $jobId)) {
                $response['message'] = "Failed to assign job to translator in the database.";
                return $response;
            }

            $job->status = 'assigned';
            $job->save();

            $client = $job->user()->firstOrFail();
            $this->notifyClientJobAccepted($client, $job, $mailer);
            $language = $teHelper->fetchLanguageFromJobId($job->from_language_id);

            $this->sendJobAcceptedNotification($currentUser, $job, $language, $teHelper);

            $response = [
                'status' => 'success',
                'list' => ['job' => $job],
                'message' => "Du har nu accepterat och fått bokningen för ${language} tolk ${job->duration}min ${job->due}"
            ];
            
        } catch (ModelNotFoundException $e) {
            $response['message'] = "The job with ID ${jobId} does not exist.";
        }

        return $response;
    }
    private function sendJobAcceptedNotification($translator, $job, $language, TeHelper $teHelper)
    {
        $notificationData = [
            'notification_type' => 'job_accepted'
        ];
        $messageText = [
            "en" => "Your booking for ${language} translators, ${job->duration}min, ${job->due} has been accepted by a translator. Please open the app for translator details."
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $notificationData, $messageText, $this->isNeedToDelayPush($translator->id));
        }
    }


    // public function cancelJobAjax($data, $user)
    // {
    //     $response = array();
    //     /*@todo
    //         add 24hrs loging here.
    //         If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
    //         if the cancelation is within 24
    //         if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
    //         so we must treat it as if it was an executed session
    //     */
    //     $cuser = $user;
    //     $job_id = $data['job_id'];
    //     $job = Job::findOrFail($job_id);
    //     $translator = Job::getJobsAssignedTranslatorDetail($job);
    //     if ($cuser->is('customer')) {
    //         $job->withdraw_at = Carbon::now();
    //         if ($job->withdraw_at->diffInHours($job->due) >= 24) {
    //             $job->status = 'withdrawbefore24';
    //             $response['jobstatus'] = 'success';
    //         } else {
    //             $job->status = 'withdrawafter24';
    //             $response['jobstatus'] = 'success';
    //         }
    //         $job->save();
    //         Event::fire(new JobWasCanceled($job));
    //         $response['status'] = 'success';
    //         $response['jobstatus'] = 'success';
    //         if ($translator) {
    //             $data = array();
    //             $data['notification_type'] = 'job_cancelled';
    //             $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    //             $msg_text = array(
    //                 "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
    //             );
    //             if ($this->isNeedToSendPush($translator->id)) {
    //                 $users_array = array($translator);
    //                 $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
    //             }
    //         }
    //     } else {
    //         if ($job->due->diffInHours(Carbon::now()) > 24) {
    //             $customer = $job->user()->get()->first();
    //             if ($customer) {
    //                 $data = array();
    //                 $data['notification_type'] = 'job_cancelled';
    //                 $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
    //                 $msg_text = array(
    //                     "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
    //                 );
    //                 if ($this->isNeedToSendPush($customer->id)) {
    //                     $users_array = array($customer);
    //                     $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
    //                 }
    //             }
    //             $job->status = 'pending';
    //             $job->created_at = date('Y-m-d H:i:s');
    //             $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
    //             $job->save();
    //         //    Event::fire(new JobWasCanceled($job));
    //             Job::deleteTranslatorJobRel($translator->id, $job_id);

    //             $data = $this->jobToData($job);

    //             $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators
    //             $response['status'] = 'success';
    //         } else {
    //             $response['status'] = 'fail';
    //             $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
    //         }
    //     }
    //     return $response;
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Handles job cancellation requests via AJAX.
     *
     * @param array $data Contains the job identification data.
     * @param User $user The user who is requesting the job cancellation.
     * @return array Response indicating the status of the cancellation request.
     */
    public function cancelJobAjax($data, $user)
    {
        $jobId = $data['job_id'];
        $job = Job::findOrFail($jobId);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $response = [
            'status' => 'fail',
            'message' => '',
        ];

        try {
            if ($user->is('customer')) {
                // Handle customer cancellation logic.
                $response = $this->handleCustomerCancellation($job, $translator);
            } else {
                // Handle translator cancellation logic.
                $response = $this->handleTranslatorCancellation($job, $translator);
            }
        } catch (ModelNotFoundException $e) {
            $response['message'] = "Job with ID {$jobId} was not found.";
        }

        return $response;
    }
    /**
     * Handles customer cancellation logic.
     *
     * @param Job $job The job being cancelled.
     * @param User $translator The assigned translator for the job, if any.
     * @return array The response details after processing.
     */
    private function handleCustomerCancellation($job, $translator)
    {
        // Add 24hrs logging code here if needed.
        $withdrawnAt = Carbon::now();
        $job->withdraw_at = $withdrawnAt;
        if ($withdrawnAt->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }
        $job->save();
        Event::fire(new JobWasCanceled($job));

        if ($translator) {
            $this->notifyCancellation($translator, $job, 'job_cancelled');
        }
        
        return [
            'status' => 'success',
            'jobstatus' => $job->status
        ];
    }
    /**
     * Handles translator cancellation logic.
     *
     * @param Job $job The job being cancelled.
     * @param User $translator The assigned translator for the job.
     * @return array The response details after processing.
     */
    private function handleTranslatorCancellation($job, $translator)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $customer = $job->user()->first();
            if ($customer) {
                $this->notifyCancellation($customer, $job, 'job_cancelled');
            }
            
            $job->status = 'pending';
            $job->created_at = date('Y-m-d H:i:s');
            $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
            $job->save();
            Job::deleteTranslatorJobRel($translator->id, $job->id);

            $data = $this->jobToData($job);
            $this->sendNotificationToSuitableTranslators($job, $data);
            // public function sendNotificationTranslator($job, array $data, $exclude_user_id)
            
            return [
                'status' => 'success'
            ];
        } else {
            return [
                'status' => 'fail',
                'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk...'
            ];
        }
    }
    /**
     * Sends a cancellation notification to a user.
     *
     * @param User $user The user to notify.
     * @param Job $job The job that has been cancelled.
     * @param string $notificationType The type of notification to send.
     */
    private function notifyCancellation($user, $job, $notificationType)
    {
        // Notification sending logic here.
        // Example:
        $data = [
            'notification_type' => $notificationType,
        ];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => "The booking for ${language} translation of ${job->duration}min on ${job->due} has been cancelled..."
        ];
        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }
    // Additional private methods would include sendNotificationToSuitableTranslators and jobToData.
    /**
     * Sends a notification to all suitable translators when a job is cancelled by a translator and is available again.
     *
     * @param Job $job The job that needs to be reassigned.
     * @param array $jobData Data representation of the job for the notification payload.
     */
    private function sendNotificationToSuitableTranslators($job, $jobData)
    {
        // Fetch suitable translators based on job criteria.
        $suitableTranslators = $this->findSuitableTranslatorsForJob($job);

        // Create common notification payload data.
        $notificationType = 'job_available';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => "A new job for ${language} translation of ${job->duration}min on ${job->due} is now available. Please check if you are available to accept it."
        ];

        // Loop through each suitable translator and send the notification.
        foreach ($suitableTranslators as $translator) {
            if ($this->isNeedToSendPush($translator->id)) {
                $this->sendPushNotificationToSpecificUsers([$translator], $job->id, [
                    'notification_type' => $notificationType,
                    'job' => $jobData
                ], $msg_text, $this->isNeedToDelayPush($translator->id));
            }
        }
    }
    /**
     * Finds suitable translators for a job based on criteria like language skill, availability, etc.
     *
     * @param Job $job The job to find suitable translators for.
     * @return array An array of User objects representing the suitable translators.
     */
    private function findSuitableTranslatorsForJob($job)
    {
        // Example criteria: translators with the right language skills and availability.
        // Note: The actual criteria and retrieval logic would be more complex and depends on the application needs.
        // For simplicity, we are retrieving all translators with the correct language pair proficiency.
        $languageFromId = $job->from_language_id;
        $languageToId = $job->to_language_id;
        return User::where('active', true)
            ->whereHas('languages', function ($query) use ($languageFromId, $languageToId) {
                $query->where('from_language_id', $languageFromId)
                    ->where('to_language_id', $languageToId);
            })
            ->get();
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    // public function getPotentialJobs($cuser)
    // {
    //     $cuser_meta = $cuser->userMeta;
    //     $job_type = 'unpaid';
    //     $translator_type = $cuser_meta->translator_type;
    //     if ($translator_type == 'professional')
    //         $job_type = 'paid';   /*show all jobs for professionals.*/
    //     else if ($translator_type == 'rwstranslator')
    //         $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
    //     else if ($translator_type == 'volunteer')
    //         $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

    //     $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
    //     $userlanguage = collect($languages)->pluck('lang_id')->all();
    //     $gender = $cuser_meta->gender;
    //     $translator_level = $cuser_meta->translator_level;
    //     /*Call the town function for checking if the job physical, then translators in one town can get job*/
    //     $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
    //     foreach ($job_ids as $k => $job) {
    //         $jobuserid = $job->user_id;
    //         $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
    //         $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
    //         $checktown = Job::checkTowns($jobuserid, $cuser->id);

    //         if($job->specific_job == 'SpecificJob')
    //             if ($job->check_particular_job == 'userCanNotAcceptJob')
    //             unset($job_ids[$k]);

    //         if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
    //             unset($job_ids[$k]);
    //         }
    //     }
    // //    $jobs = TeHelper::convertJobIdsInObjs($job_ids);
    //     return $job_ids;
    // }
    // Improve:
    // -------------------------------------------
    /**
     * Gets the potential jobs for a translator based on their profile and job criteria.
     *
     * @param User $translator The translator for whom to fetch potential jobs.
     * @return Collection The potential jobs for the translator.
     */
    public function getPotentialJobs($translator)
    {
        $jobType = $this->determineJobType($translator,[]);
        $languageIds = $translator->languages()->pluck('lang_id')->all();
        $gender = $translator->userMeta->gender;
        $translatorLevel = $translator->userMeta->translator_level;

        // Retrieve all jobs that match the translator's criteria
        $potentialJobs = Job::where('status', 'pending')
                            ->when($jobType, function ($query) use ($jobType) {
                                return $query->where('type', $jobType);
                            })
                            ->whereIn('from_language_id', $languageIds)
                            ->where('gender', $gender)
                            ->where('translator_level', $translatorLevel)
                            ->get();

        // Filter out jobs that the translator cannot accept or those not in their location
        return $potentialJobs->reject(function ($job) use ($translator) {
            return !$this->canAcceptJob($job, $translator) || !$this->isInTranslatorTown($job, $translator);
        });
    }
    /**
     * Checks if the translator can accept the specified job.
     *
     * @param Job $job The job to check.
     * @param User $translator The translator to check for.
     * @return bool Whether the translator can accept the job.
     */
    private function canAcceptJob($job, $translator)
    {
        return Job::assignedToPaticularTranslator($translator->id, $job->id) &&
            Job::checkParticularJob($translator->id, $job);
    }
    /**
     * Checks if the job is in the same town as the translator for physical jobs.
     *
     * @param Job $job The job to check.
     * @param User $translator The translator to check against.
     * @return bool Whether the job is in the same town as the translator.
     */
    private function isInTranslatorTown($job, $translator)
    {
        if ($job->customer_physical_type == 'yes') {
            return Job::checkTowns($job->user_id, $translator->id);
        }
        return true;  // Non-physical job or not relevant
    }


    // public function endJob($post_data)
    // {
    //     $completeddate = date('Y-m-d H:i:s');
    //     $jobid = $post_data["job_id"];
    //     $job_detail = Job::with('translatorJobRel')->find($jobid);

    //     if($job_detail->status != 'started')
    //         return ['status' => 'success'];

    //     $duedate = $job_detail->due;
    //     $start = date_create($duedate);
    //     $end = date_create($completeddate);
    //     $diff = date_diff($end, $start);
    //     $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
    //     $job = $job_detail;
    //     $job->end_at = date('Y-m-d H:i:s');
    //     $job->status = 'completed';
    //     $job->session_time = $interval;

    //     $user = $job->user()->get()->first();
    //     if (!empty($job->user_email)) {
    //         $email = $job->user_email;
    //     } else {
    //         $email = $user->email;
    //     }
    //     $name = $user->name;
    //     $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //     $session_explode = explode(':', $job->session_time);
    //     $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
    //     $data = [
    //         'user'         => $user,
    //         'job'          => $job,
    //         'session_time' => $session_time,
    //         'for_text'     => 'faktura'
    //     ];
    //     $mailer = new AppMailer();
    //     $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

    //     $job->save();

    //     $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

    //     Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

    //     $user = $tr->user()->first();
    //     $email = $user->email;
    //     $name = $user->name;
    //     $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
    //     $data = [
    //         'user'         => $user,
    //         'job'          => $job,
    //         'session_time' => $session_time,
    //         'for_text'     => 'lön'
    //     ];
    //     $mailer = new AppMailer();
    //     $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

    //     $tr->completed_at = $completeddate;
    //     $tr->completed_by = $post_data['user_id'];
    //     $tr->save();
    //     $response['status'] = 'success';
    //     return $response;
    // }
    // Improve:
    // -------------------------------------------
    public function endJob($post_data)
    {
        $job = Job::with('translatorJobRel', 'user')->findOrFail($post_data["job_id"]);

        if ($job->status != 'started') {
            return ['status' => 'job_not_started'];
        }

        $job->end_at = now();
        $job->status = 'completed';
        $job->session_time = $job->due->diffForHumans(now(), true); // Menggunakan Carbon

        $email = $job->user_email ?: $job->user->email;
        $data = [
            'user'         => $job->user,
            'job'          => $job,
            'session_time' => $job->session_time,
            'for_text'     => 'faktura', // Teks bisa diganti sesuai kebutuhan
        ];

        Mail::to($email)->send(new SessionEnded($data)); // Dengan asumsi ada Mailable

        $job->save();

        $translator = $job->translatorJobRel()->whereNull('completed_at')->whereNull('cancel_at')->firstOrFail();

        event(new SessionEnded($job, $post_data['user_id'] == $job->user_id ? $translator->user_id : $job->user_id));

        $translator->completed_at = now();
        $translator->completed_by = $post_data['user_id'];
        $translator->save();

        return ['status' => 'success'];
    }

    // public function customerNotCall($post_data)
    // {
    //     $completeddate = date('Y-m-d H:i:s');
    //     $jobid = $post_data["job_id"];
    //     $job_detail = Job::with('translatorJobRel')->find($jobid);
    //     $duedate = $job_detail->due;
    //     $start = date_create($duedate);
    //     $end = date_create($completeddate);
    //     $diff = date_diff($end, $start);
    //     $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
    //     $job = $job_detail;
    //     $job->end_at = date('Y-m-d H:i:s');
    //     $job->status = 'not_carried_out_customer';

    //     $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
    //     $tr->completed_at = $completeddate;
    //     $tr->completed_by = $tr->user_id;
    //     $job->save();
    //     $tr->save();
    //     $response['status'] = 'success';
    //     return $response;
    // }
    // Improve:
    // -------------------------------------------
    public function customerNotCall($post_data)
    {
        $jobid = $post_data['job_id'];
        $job = Job::with('translatorJobRel')->findOrFail($jobid);
        $completedDate = now(); // Menggunakan Carbon

        // Pembaruan job dilakukan dalam transaksi untuk memastikan integritas data
        DB::transaction(function () use ($job, $completedDate) {
            $job->update([
                'end_at' => $completedDate,
                'status' => 'not_carried_out_customer',
            ]);

            $translator = $job->translatorJobRel()
                              ->whereNull('completed_at')
                              ->whereNull('cancel_at')
                              ->firstOrFail();

            $translator->update([
                'completed_at' => $completedDate,
                'completed_by' => $translator->user_id,
            ]);
        });

        return ['status' => 'completed_with_no_customer_call'];
    }

    // public function getAll(Request $request, $limit = null)
    // {
    //     $requestdata = $request->all();
    //     $cuser = $request->__authenticatedUser;
    //     $consumer_type = $cuser->consumer_type;

    //     if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
    //         $allJobs = Job::query();

    //         if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
    //             $allJobs->where('ignore_feedback', '0');
    //             $allJobs->whereHas('feedback', function ($q) {
    //                 $q->where('rating', '<=', '3');
    //             });
    //             if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
    //         }

    //         if (isset($requestdata['id']) && $requestdata['id'] != '') {
    //             if (is_array($requestdata['id']))
    //                 $allJobs->whereIn('id', $requestdata['id']);
    //             else
    //                 $allJobs->where('id', $requestdata['id']);
    //             $requestdata = array_only($requestdata, ['id']);
    //         }

    //         if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
    //             $allJobs->whereIn('from_language_id', $requestdata['lang']);
    //         }
    //         if (isset($requestdata['status']) && $requestdata['status'] != '') {
    //             $allJobs->whereIn('status', $requestdata['status']);
    //         }
    //         if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
    //             $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
    //         }
    //         if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
    //             $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
    //         }
    //         if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
    //             $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();
    //             if ($users) {
    //                 $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
    //             }
    //         }
    //         if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
    //             $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();
    //             if ($users) {
    //                 $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
    //                 $allJobs->whereIn('id', $allJobIDs);
    //             }
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('created_at', '>=', $requestdata["from"]);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('created_at', '<=', $to);
    //             }
    //             $allJobs->orderBy('created_at', 'desc');
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('due', '>=', $requestdata["from"]);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('due', '<=', $to);
    //             }
    //             $allJobs->orderBy('due', 'desc');
    //         }

    //         if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
    //             $allJobs->whereIn('job_type', $requestdata['job_type']);
    //             /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
    //         }

    //         if (isset($requestdata['physical'])) {
    //             $allJobs->where('customer_physical_type', $requestdata['physical']);
    //             $allJobs->where('ignore_physical', 0);
    //         }

    //         if (isset($requestdata['phone'])) {
    //             $allJobs->where('customer_phone_type', $requestdata['phone']);
    //             if(isset($requestdata['physical']))
    //             $allJobs->where('ignore_physical_phone', 0);
    //         }

    //         if (isset($requestdata['flagged'])) {
    //             $allJobs->where('flagged', $requestdata['flagged']);
    //             $allJobs->where('ignore_flagged', 0);
    //         }

    //         if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
    //             $allJobs->whereDoesntHave('distance');
    //         }

    //         if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
    //             $allJobs->whereDoesntHave('user.salaries');
    //         }

    //         if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
    //             $allJobs = $allJobs->count();

    //             return ['count' => $allJobs];
    //         }

    //         if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
    //             $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
    //                 $q->where('consumer_type', $requestdata['consumer_type']);
    //             });
    //         }

    //         if (isset($requestdata['booking_type'])) {
    //             if ($requestdata['booking_type'] == 'physical')
    //                 $allJobs->where('customer_physical_type', 'yes');
    //             if ($requestdata['booking_type'] == 'phone')
    //                 $allJobs->where('customer_phone_type', 'yes');
    //         }
            
    //         $allJobs->orderBy('created_at', 'desc');
    //         $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    //         if ($limit == 'all')
    //             $allJobs = $allJobs->get();
    //         else
    //             $allJobs = $allJobs->paginate(15);

    //     } else {

    //         $allJobs = Job::query();

    //         if (isset($requestdata['id']) && $requestdata['id'] != '') {
    //             $allJobs->where('id', $requestdata['id']);
    //             $requestdata = array_only($requestdata, ['id']);
    //         }

    //         if ($consumer_type == 'RWS') {
    //             $allJobs->where('job_type', '=', 'rws');
    //         } else {
    //             $allJobs->where('job_type', '=', 'unpaid');
    //         }
    //         if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
    //             $allJobs->where('ignore_feedback', '0');
    //             $allJobs->whereHas('feedback', function($q) {
    //                 $q->where('rating', '<=', '3');
    //             });
    //             if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
    //         }
            
    //         if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
    //             $allJobs->whereIn('from_language_id', $requestdata['lang']);
    //         }
    //         if (isset($requestdata['status']) && $requestdata['status'] != '') {
    //             $allJobs->whereIn('status', $requestdata['status']);
    //         }
    //         if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
    //             $allJobs->whereIn('job_type', $requestdata['job_type']);
    //         }
    //         if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
    //             $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
    //             if ($user) {
    //                 $allJobs->where('user_id', '=', $user->id);
    //             }
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('created_at', '>=', $requestdata["from"]);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('created_at', '<=', $to);
    //             }
    //             $allJobs->orderBy('created_at', 'desc');
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('due', '>=', $requestdata["from"]);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('due', '<=', $to);
    //             }
    //             $allJobs->orderBy('due', 'desc');
    //         }

    //         $allJobs->orderBy('created_at', 'desc');
    //         $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    //         if ($limit == 'all')
    //             $allJobs = $allJobs->get();
    //         else
    //             $allJobs = $allJobs->paginate(15);

    //     }
    //     return $allJobs;
    // }
    // Improve:
    // -------------------------------------------
    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $authenticatedUser = $request->__authenticatedUser;
        $consumerType = $authenticatedUser->consumer_type;

        $query = Job::query();
        $this->applySuperAdminFilters($query, $requestData, $authenticatedUser);
        $this->applyCommonFilters($query, $requestData);

        if ($authenticatedUser && $authenticatedUser->user_type != env('SUPERADMIN_ROLE_ID')) {
            $this->applyNonSuperAdminFilters($query, $requestData, $consumerType);
        }

        $this->addEagerLoads($query);

        return $limit == 'all' ? $query->get() : $query->paginate(15);
    }
    private function applySuperAdminFilters($query, $requestData, $authenticatedUser)
    {
        if ($authenticatedUser->user_type !== env('SUPERADMIN_ROLE_ID')) {
            return;
        }

        // Filter for feedback
        if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestData['count']) && $requestData['count'] != 'false') {
                return ['count' => $query->count()];
            }
        }

        // ID filter
        if (!empty($requestData['id'])) {
            if (is_array($requestData['id'])) {
                $query->whereIn('id', $requestData['id']);
            } else {
                $query->where('id', $requestData['id']);
            }
        }

        // Additional filters
        $this->applyFiltersFromRequest($query, $requestData, [
            'lang' => 'from_language_id',
            'status' => 'status',
            'expired_at' => '>=',
            'will_expire_at' => '>=',
            'customer_email' => function ($q, $value) {
                $users = DB::table('users')->whereIn('email', $value)->get();
                if ($users->isNotEmpty()) {
                    $q->whereIn('user_id', $users->pluck('id')->all());
                }
            },
            'translator_email' => function ($q, $value) {
                $users = DB::table('users')->whereIn('email', $value)->get();
                if ($users->isNotEmpty()) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $users->pluck('id')->all())->pluck('job_id');
                    $q->whereIn('id', $allJobIDs);
                }
            }
        ]);

        $this->applyDateFilters($query, $requestData);
        $this->applyBookingTypeFilter($query, $requestData);
        $this->applyIgnoreFlagsFilter($query, $requestData);

        $query->orderBy('created_at', 'desc');
    }
    private function applyFiltersFromRequest($query, $requestData, $filters)
    {
        foreach ($filters as $key => $columnOrClosure) {
            if (!isset($requestData[$key]) || $requestData[$key] === '') continue;

            if (is_callable($columnOrClosure)) {
                $columnOrClosure($query, $requestData[$key]);
            } else if (is_array($columnOrClosure)) {
                list($column, $operator) = $columnOrClosure;
                $query->where($column, $operator, $requestData[$key]);
            } else {
                $query->whereIn($columnOrClosure, $requestData[$key]);
            }
        }
    }
    private function applyDateFilters($query, $requestData)
    {
        // Filter by created date
        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == 'created') {
            if (isset($requestData['from']) && $requestData['from'] != '') {
                $query->where('created_at', '>=', $requestData['from']);
            }
            if (isset($requestData['to']) && $requestData['to'] != '') {
                $to = $requestData['to'] . " 23:59:00";
                $query->where('created_at', '<=', $to);
            }
            $query->orderBy('created_at', 'desc');
        }
        
        // Filter by due date
        if (isset($requestData['filter_timetype']) && $requestData['filter_timetype'] == 'due') {
            if (isset($requestData['from']) && $requestData['from'] != '') {
                $query->where('due', '>=', $requestData['from']);
            }
            if (isset($requestData['to']) && $requestData['to'] != '') {
                $to = $requestData['to'] . " 23:59:00";
                $query->where('due', '<=', $to);
            }
            $query->orderBy('due', 'desc');
        }
    }
    private function applyBookingTypeFilter($query, $requestData)
    {
        // Filter by booking type
        if (isset($requestData['booking_type'])) {
            switch ($requestData['booking_type']) {
                case 'physical':
                    $query->where('customer_physical_type', 'yes');
                    break;
                case 'phone':
                    $query->where('customer_phone_type', 'yes');
                    break;
            }
        }
    }
    private function applyIgnoreFlagsFilter($query, $requestData)
    {
        // Filter by ignoring flags
        if (isset($requestData['physical']) && $requestData['physical']) {
            $query->where('customer_physical_type', $requestData['physical'])
                ->where('ignore_physical', 0);
        }
        if (isset($requestData['phone']) && $requestData['phone']) {
            $query->where('customer_phone_type', $requestData['phone']);
            if (isset($requestData['physical'])) {
                $query->where('ignore_physical_phone', 0);
            }
        }
        if (isset($requestData['flagged']) && $requestData['flagged']) {
            $query->where('flagged', $requestData['flagged'])
                ->where('ignore_flagged', 0);
        }
        if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
            $query->whereDoesntHave('distance');
        }
        if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
            $query->whereDoesntHave('user.salaries');
        }
    }
    private function applyNonSuperAdminFilters($query, $requestData, $consumerType)
    {
        // Filter by consumer type
        if ($consumerType == 'RWS') {
            $query->where('job_type', '=', 'rws');
        } else {
            $query->where('job_type', '=', 'unpaid');
        }

        // Feedback filter if applicable for non-SuperAdmin users
        if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if(isset($requestData['count']) && $requestData['count'] != 'false') {
                return ['count' => $query->count()];
            }
        }

        // Apply any date filters if specified
        $this->applyDateFilters($query, $requestData);

        // Apply additional filters if specified
        $this->applyAdditionalFilters($query, $requestData);

        // Apply eager loading of related entities
        $this->addEagerLoads($query);
    }
    private function applyAdditionalFilters($query, $requestData)
    {
        if (isset($requestData['id']) && $requestData['id'] != '') {
            $query->where('id', $requestData['id']);
        }

        if (isset($requestData['lang']) && $requestData['lang'] != '') {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (isset($requestData['status']) && $requestData['status'] != '') {
            $query->whereIn('status', $requestData['status']);
        }

        if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
            if ($user) {
                $query->where('user_id', '=', $user->id);
            }
        }
    }
    private function applyCommonFilters($query, $requestData)
    {
        // Filtration based on job ID
        if (!empty($requestData['id'])) {
            if (is_array($requestData['id'])) {
                $query->whereIn('id', $requestData['id']);
            } else {
                $query->where('id', $requestData['id']);
            }
        }

        // Filter by language
        if (!empty($requestData['lang'])) {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        // Filter by job status
        if (!empty($requestData['status'])) {
            $query->whereIn('status', $requestData['status']);
        }

        // Filter by job type
        if (!empty($requestData['job_type'])) {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        // Filter by time type, from and to date
        // As applyDateFilters logic should be common to all, it can be used here directly.
        $this->applyDateFilters($query, $requestData);

        // Filter by both booking physical and phone type
        $this->applyBookingTypeFilter($query, $requestData);

        // Filter by flags and if the job has distance and salaries associated
        $this->applyIgnoreFlagsFilter($query, $requestData);

        // Apply eager loads that are required for every user type
        $this->addEagerLoads($query);
    }
    private function addEagerLoads($query)
    {
        $query->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    }

    // public function alerts()
    // {
    //     $jobs = Job::all();
    //     $sesJobs = [];
    //     $jobId = [];
    //     $diff = [];
    //     $i = 0;

    //     foreach ($jobs as $job) {
    //         $sessionTime = explode(':', $job->session_time);
    //         if (count($sessionTime) >= 3) {
    //             $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

    //             if ($diff[$i] >= $job->duration) {
    //                 if ($diff[$i] >= $job->duration * 2) {
    //                     $sesJobs [$i] = $job;
    //                 }
    //             }
    //             $i++;
    //         }
    //     }

    //     foreach ($sesJobs as $job) {
    //         $jobId [] = $job->id;
    //     }

    //     $languages = Language::where('active', '1')->orderBy('language')->get();
    //     $requestdata = Request::all();
    //     $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
    //     $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

    //     $cuser = Auth::user();
    //     $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


    //     if ($cuser && $cuser->is('superadmin')) {
    //         $allJobs = DB::table('jobs')
    //             ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
    //         if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
    //             $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
    //                 ->where('jobs.ignore', 0);
    //             /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
    //         }
    //         if (isset($requestdata['status']) && $requestdata['status'] != '') {
    //             $allJobs->whereIn('jobs.status', $requestdata['status'])
    //                 ->where('jobs.ignore', 0);
    //             /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
    //         }
    //         if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
    //             $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
    //             if ($user) {
    //                 $allJobs->where('jobs.user_id', '=', $user->id)
    //                     ->where('jobs.ignore', 0);
    //             }
    //         }
    //         if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
    //             $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
    //             if ($user) {
    //                 $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
    //                 $allJobs->whereIn('jobs.id', $allJobIDs)
    //                     ->where('jobs.ignore', 0);
    //             }
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
    //                     ->where('jobs.ignore', 0);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('jobs.created_at', '<=', $to)
    //                     ->where('jobs.ignore', 0);
    //             }
    //             $allJobs->orderBy('jobs.created_at', 'desc');
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('jobs.due', '>=', $requestdata["from"])
    //                     ->where('jobs.ignore', 0);
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('jobs.due', '<=', $to)
    //                     ->where('jobs.ignore', 0);
    //             }
    //             $allJobs->orderBy('jobs.due', 'desc');
    //         }

    //         if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
    //             $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
    //                 ->where('jobs.ignore', 0);
    //             /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
    //         }
    //         $allJobs->select('jobs.*', 'languages.language')
    //             ->where('jobs.ignore', 0)
    //             ->whereIn('jobs.id', $jobId);

    //         $allJobs->orderBy('jobs.created_at', 'desc');
    //         $allJobs = $allJobs->paginate(15);
    //     }

    //     return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    // }
    // Improve:
    // -------------------------------------------
    public function alerts()
    {
        list($sesJobs, $jobId) = $this->filterJobsWithExcessSessionTime();
        $languages = $this->getActiveLanguages();
        $requestdata = Request::all();
        $all_customers = $this->getAllCustomers();
        $all_translators = $this->getAllTranslators();

        $allJobs = collect(); // Default value as empty collection
        
        if ($this->isUserSuperadmin()) {
            $allJobs = $this->getAllJobsForSuperadmin($jobId, $requestdata);
        }

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestdata
        ];
    }
    private function getActiveLanguages()
    {
        return Language::where('active', 1)->orderBy('language')->get();
    }
    private function getAllCustomers()
    {
        return User::where('user_type', 1)->pluck('email');
    }
    private function getAllTranslators()
    {
        return User::where('user_type', 2)->pluck('email');
    }
    private function isUserSuperadmin()
    {
        $user = Auth::user();

        // You may need to adjust this check based on your Auth setup and superadmin determination logic.
        return $user && $user->is('superadmin');
    }
    private function filterJobsWithExcessSessionTime()
    {
        $jobs = Job::all(); // Consider replacing with a more efficient query if possible.
        $sesJobs = [];
        $jobIds = [];

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) < 3) {
                continue;
            }

            $diffInMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
            if ($diffInMinutes >= $job->duration * 2) {
                $sesJobs[] = $job;
            }
        }

        $jobIds = collect($sesJobs)->pluck('id');

        return [$sesJobs, $jobIds];
    }
    private function getAllJobsForSuperadmin($jobIds, $requestdata)
    {
        $query = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore', 0)
            ->whereIn('jobs.id', $jobIds)
            ->select('jobs.*', 'languages.language');

        // Apply filters based on request data
        $query = $this->applyRequestFilters($query, $requestdata);

        return $query->orderBy('jobs.created_at', 'desc')->paginate(15);
    }
    private function applyRequestFilters($query, $requestdata)
    {
        foreach ($requestdata as $filter => $value) {
            if (empty($value)) {
                continue;
            }

            $query = $this->applyIndividualFilter($query, $filter, $value);
        }

        return $query;
    }
    protected function applyIndividualFilter($query, $filterKey, $value)
    {
        if (empty($value)) {
            return $query;
        }
        switch ($filterKey) {
            case 'lang':
                $query->whereIn('jobs.from_language_id', (array)$value);
                break;
            case 'status':
                $query->whereIn('jobs.status', (array)$value);
                break;
            case 'customer_email':
                $customerId = User::where('email', $value)->value('id');
                if ($customerId) {
                    $query->where('jobs.user_id', $customerId);
                }
                break;
            case 'translator_email':
                $translatorIds = User::where('email', $value)->pluck('id');
                $jobIdsForTranslator = DB::table('translator_job_rel')
                                        ->whereIn('user_id', $translatorIds)
                                        ->pluck('job_id');
                return $query->whereIn('id', $jobIdsForTranslator);
    
            case 'filter_timetype':
                return $this->applyTimeFilter($query, $value, $requestdata);
            // Add other filters here
            // ...
            default:
                // No matching filter found
                \Log::warning("Unknown filter key: {$filterKey} with value: {$value}");
                break;
        }
        return $query;
    }

    // public function userLoginFailed()
    // {
    //     $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

    //     return ['throttles' => $throttles];
    // }
    // Improve:
    // -------------------------------------------
    // Define a constant for better code readability
    const THROTTLE_IGNORED = 0;
    public function userLoginFailed()
    {
        // Using the constant instead of the magic number for 'ignore'
        $throttles = Throttles::where('ignore', self::THROTTLE_IGNORED)
            ->with('user')
            ->paginate(15);

        return ['throttles' => $throttles];
    }

    // public function bookingExpireNoAccepted()
    // {
    //     $languages = Language::where('active', '1')->orderBy('language')->get();
    //     $requestdata = Request::all();
    //     $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
    //     $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

    //     $cuser = Auth::user();
    //     $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


    //     if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
    //         $allJobs = DB::table('jobs')
    //             ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
    //             ->where('jobs.ignore_expired', 0);
    //         if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
    //             $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
    //                 ->where('jobs.status', 'pending')
    //                 ->where('jobs.ignore_expired', 0)
    //                 ->where('jobs.due', '>=', Carbon::now());
    //             /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
    //         }
    //         if (isset($requestdata['status']) && $requestdata['status'] != '') {
    //             $allJobs->whereIn('jobs.status', $requestdata['status'])
    //                 ->where('jobs.status', 'pending')
    //                 ->where('jobs.ignore_expired', 0)
    //                 ->where('jobs.due', '>=', Carbon::now());
    //             /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
    //         }
    //         if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
    //             $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
    //             if ($user) {
    //                 $allJobs->where('jobs.user_id', '=', $user->id)
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //         }
    //         if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
    //             $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
    //             if ($user) {
    //                 $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
    //                 $allJobs->whereIn('jobs.id', $allJobIDs)
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('jobs.created_at', '<=', $to)
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //             $allJobs->orderBy('jobs.created_at', 'desc');
    //         }
    //         if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
    //             if (isset($requestdata['from']) && $requestdata['from'] != "") {
    //                 $allJobs->where('jobs.due', '>=', $requestdata["from"])
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //             if (isset($requestdata['to']) && $requestdata['to'] != "") {
    //                 $to = $requestdata["to"] . " 23:59:00";
    //                 $allJobs->where('jobs.due', '<=', $to)
    //                     ->where('jobs.status', 'pending')
    //                     ->where('jobs.ignore_expired', 0)
    //                     ->where('jobs.due', '>=', Carbon::now());
    //             }
    //             $allJobs->orderBy('jobs.due', 'desc');
    //         }

    //         if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
    //             $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
    //                 ->where('jobs.status', 'pending')
    //                 ->where('jobs.ignore_expired', 0)
    //                 ->where('jobs.due', '>=', Carbon::now());
    //             /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
    //         }
    //         $allJobs->select('jobs.*', 'languages.language')
    //             ->where('jobs.status', 'pending')
    //             ->where('ignore_expired', 0)
    //             ->where('jobs.due', '>=', Carbon::now());

    //         $allJobs->orderBy('jobs.created_at', 'desc');
    //         $allJobs = $allJobs->paginate(15);

    //     }
    //     return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    // }
    // Improve:
    // -------------------------------------------
    public function bookingExpireNoAccepted()
    {
        // Load active languages
        $languages = Language::active()->get();

        // Collect input
        $requestData = request()->all();

        // Retrieve emails of customers and translators
        $all_customers = User::where('user_type', 1)->pluck('email');
        $all_translators = User::where('user_type', 2)->pluck('email');

        // Authenticate user and check for admin privileges
        $currentUser = Auth::user();
        if (!$currentUser || (!$currentUser->is('superadmin') && !$currentUser->is('admin'))) {
            return [
                'allJobs' => null,
                'languages' => $languages,
                'all_customers' => $all_customers,
                'all_translators' => $all_translators,
                'requestdata' => $requestData
            ];
        }

        // Use query scopes and methods to refactor the job query
        $jobsQuery = Job::query()->with('languages:language')
                            ->notExpired()->pending()->dueInFuture();

        // Filtering logic can be extracted into method calls or scopes for better readability
        $jobsQuery = $this->applyFilters($jobsQuery, $requestData);

        // Order by and pagination can be applied at the end
        $jobsQuery->latest('created_at');
        $allJobs = $jobsQuery->paginate(15);

        return [
            'allJobs' => $allJobs,
            'languages' => $languages,
            'all_customers' => $all_customers,
            'all_translators' => $all_translators,
            'requestdata' => $requestData
        ];
    }
    protected function applyFilters($query, $requestData)
    {
        // Filter by language if provided
        if (!empty($requestData['lang'])) {
            $query->whereIn('jobs.from_language_id', $requestData['lang']);
        }
        
        // Filter by status if provided
        if (!empty($requestData['status'])) {
            $query->whereIn('jobs.status', $requestData['status']);
        }
        
        // Filter by customer email if provided
        if (!empty($requestData['customer_email'])) {
            $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
            if ($user) {
                $query->where('jobs.user_id', $user->id);
            }
        }
        
        // Filter by translator email if provided
        if (!empty($requestData['translator_email'])) {
            $user = DB::table('users')->where('email', $requestData['translator_email'])->first();
            if ($user) {
                $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->pluck('job_id');
                $query->whereIn('jobs.id', $allJobIDs);
            }
        }
        
        // Filter by created_at or due date based on 'filter_timetype'
        if (isset($requestData['filter_timetype']) && in_array($requestData['filter_timetype'], ['created', 'due'])) {
            $field = ($requestData['filter_timetype'] === 'created') ? 'jobs.created_at' : 'jobs.due';
            if (isset($requestData['from']) && $requestData['from'] != "") {
                $query->where($field, '>=', $requestData['from']);
            }
            if (isset($requestData['to']) && $requestData['to'] != "") {
                $to = $requestData['to'] . " 23:59:00";
                $query->where($field, '<=', $to);
            }
            $query->orderBy($field, 'desc');
        }

        // Filter by job_type if provided
        if (!empty($requestData['job_type'])) {
            $query->whereIn('jobs.job_type', $requestData['job_type']);
        }

        // Apply generic filters
        // Assume jobs.due is already a DateTime object or format that suits the database comparison
        $query->where('jobs.status', 'pending')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.due', '>=', Carbon::now());

        // Note: Ensure Carbon is imported at the top of your script with use Carbon\Carbon;
        
        return $query;
    }

    // public function ignoreExpiring($id)
    // {
    //     $job = Job::find($id);
    //     $job->ignore = 1;
    //     $job->save();
    //     return ['success', 'Changes saved'];
    // }
    // Improve:
    // -------------------------------------------
    public function ignoreExpiring($id)
    {
        try {
            $job = Job::findOrFail($id); // Automatically throws a 404 exception if not found
            $job->ignore = 1;

            if ($job->save()) {
                // Success response with HTTP status code 200 (OK)
                return response()->json(['message' => 'Changes saved'], 200);
            } else {
                // Error response with HTTP status code 500 (Internal Server Error)
                return response()->json(['error' => 'Could not save changes'], 500);
            }
        } catch (ModelNotFoundException $e) {
            // Not found response with HTTP status code 404 (Not Found)
            return response()->json(['error' => 'Job not found'], 404);
        }
    }

    // public function ignoreExpired($id)
    // {
    //     $job = Job::find($id);
    //     $job->ignore_expired = 1;
    //     $job->save();
    //     return ['success', 'Changes saved'];
    // }
    // Improve:
    // -------------------------------------------
    public function ignoreExpired($id)
    {
        try {
            $job = Job::findOrFail($id); // Automatically throws a 404 exception if not found
            $job->ignore_expired = 1;

            if ($job->save()) {
                // Success response with HTTP status code 200 (OK)
                return response()->json(['message' => 'Changes saved'], 200);
            } else {
                // Error response with HTTP status code 500 (Internal Server Error)
                return response()->json(['error' => 'Could not save changes'], 500);
            }
        } catch (ModelNotFoundException $e) {
            // Not found response with HTTP status code 404 (Not Found)
            return response()->json(['error' => 'Job not found'], 404);
        }
    }

    // public function ignoreThrottle($id)
    // {
    //     $throttle = Throttles::find($id);
    //     $throttle->ignore = 1;
    //     $throttle->save();
    //     return ['success', 'Changes saved'];
    // }
    // Improve:
    // -------------------------------------------
    public function ignoreThrottle($id)
    {
        try {
            $throttle = Throttles::findOrFail($id); // Automatically throws a 404 exception if not found
            $throttle->ignore_expired = 1;

            if ($throttle->save()) {
                // Success response with HTTP status code 200 (OK)
                return response()->json(['message' => 'Changes saved'], 200);
            } else {
                // Error response with HTTP status code 500 (Internal Server Error)
                return response()->json(['error' => 'Could not save changes'], 500);
            }
        } catch (ModelNotFoundException $e) {
            // Not found response with HTTP status code 404 (Not Found)
            return response()->json(['error' => 'Job not found'], 404);
        }
    }

    // public function reopen($request)
    // {
    //     $jobid = $request['jobid'];
    //     $userid = $request['userid'];

    //     $job = Job::find($jobid);
    //     $job = $job->toArray();

    //     $data = array();
    //     $data['created_at'] = date('Y-m-d H:i:s');
    //     $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
    //     $data['updated_at'] = date('Y-m-d H:i:s');
    //     $data['user_id'] = $userid;
    //     $data['job_id'] = $jobid;
    //     $data['cancel_at'] = Carbon::now();

    //     $datareopen = array();
    //     $datareopen['status'] = 'pending';
    //     $datareopen['created_at'] = Carbon::now();
    //     $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);
    //     //$datareopen['updated_at'] = date('Y-m-d H:i:s');

    //     //    $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobid);

    //     if ($job['status'] != 'timedout') {
    //         $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
    //         $new_jobid = $jobid;
    //     } else {
    //         $job['status'] = 'pending';
    //         $job['created_at'] = Carbon::now();
    //         $job['updated_at'] = Carbon::now();
    //         $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
    //         $job['updated_at'] = date('Y-m-d H:i:s');
    //         $job['cust_16_hour_email'] = 0;
    //         $job['cust_48_hour_email'] = 0;
    //         $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
    //         //$job[0]['user_email'] = $user_email;
    //         $affectedRows = Job::create($job);
    //         $new_jobid = $affectedRows['id'];
    //     }
    //     //$result = DB::table('translator_job_rel')->insertGetId($data);
    //     Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
    //     $Translator = Translator::create($data);
    //     if (isset($affectedRows)) {
    //         $this->sendNotificationByAdminCancelJob($new_jobid);
    //         return ["Tolk cancelled!"];
    //     } else {
    //         return ["Please try again!"];
    //     }
    // }
    // Improve:
    // -------------------------------------------
    public function reopen(Request $request)
    {
        $jobId = $request->input('jobid');
        $userId = $request->input('userid');
    
        // Find the job using Eloquent and handle job not found
        $job = Job::findOrFail($jobId);
        $due = new Carbon($job->due);
    
        $now = Carbon::now();
        $reopenData = [
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
            'will_expire_at' => TeHelper::willExpireAt($due, $now),
            'admin_comments' => $job->status === 'timedout' ? 'This booking is a reopening of booking #' . $jobId : '',
        ];
    
        // Update the job if the status is not 'timedout', otherwise create a new job
        if ($job->status !== 'timedout') {
            $job->update($reopenData);
        } else {
            // Create a new job while keeping some attributes from the old job
            $reopenData['user_id'] = $userId;
            $reopenData['cust_16_hour_email'] = 0;
            $reopenData['cust_48_hour_email'] = 0;
            $job = Job::create($reopenData);
        }
    
        // Create a cancelation record for the old job
        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $now]);
        Translator::create([
            'job_id' => $jobId,
            'user_id' => $userId,
            'created_at' => $now,
            'will_expire_at' => $due,
            'updated_at' => $now,
            'cancel_at' => $now
        ]);
    
        // Send notification and return response
        $this->sendNotificationByAdminCancelJob($job->id);
        return response()->json(['message' => 'Job reopened!']);
    }
    
    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    // private function convertToHoursMins($time, $format = '%02dh %02dmin')
    // {
    //     if ($time < 60) {
    //         return $time . 'min';
    //     } else if ($time == 60) {
    //         return '1h';
    //     }

    //     $hours = floor($time / 60);
    //     $minutes = ($time % 60);
        
    //     return sprintf($format, $hours, $minutes);
    // }
    // Improve:
    // -------------------------------------------
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time == 0) {
            return '0min';
        }

        if ($time < 60) {
            return sprintf('%02dmin', $time);
        }

        if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = $time % 60;
        
        return sprintf($format, $hours, $minutes);
    }

}
