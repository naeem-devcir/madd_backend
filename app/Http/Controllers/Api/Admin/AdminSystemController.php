<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;

class AdminSystemController extends PlaceholderApiController
{
    public function logs()
    {
        return $this->notImplemented('Admin log listing');
    }

    public function showLog(string $date)
    {
        return $this->notImplemented('Admin log details', ['date' => $date]);
    }

    public function clearLogs()
    {
        return $this->notImplemented('Admin log clearing');
    }

    public function cache()
    {
        return $this->notImplemented('Admin cache status');
    }

    public function clearCache()
    {
        return $this->notImplemented('Admin cache clearing');
    }

    public function queues()
    {
        return $this->notImplemented('Admin queue listing');
    }

    public function retryJob(string $id)
    {
        return $this->notImplemented('Admin failed job retry', ['id' => $id]);
    }

    public function clearFailedJobs()
    {
        return $this->notImplemented('Admin failed job clearing');
    }

    public function maintenanceStatus()
    {
        return $this->notImplemented('Admin maintenance status');
    }

    public function toggleMaintenance()
    {
        return $this->notImplemented('Admin maintenance toggle');
    }
}

