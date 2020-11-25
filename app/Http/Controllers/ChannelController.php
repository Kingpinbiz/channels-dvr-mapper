<?php

namespace App\Http\Controllers;

use App\Models\DvrChannel;
use App\Services\ChannelsBackendService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    protected $channelsBackend;

    public function __construct()
    {
        $this->channelsBackend = new ChannelsBackendService();
    }

    public function index()
    {
        $sources = $this->channelsBackend->getDevices();

        if($sources->count() > 0) {
            return view('layouts.main', [
                'sources' => $sources,
                'channelsBackendUrl' => $this->channelsBackend->getBaseUrl(),
            ]);
        }
        else {
            return view('empty', ['channelsBackendUrl' => $this->channelsBackend->getBaseUrl()]);
        }
    }

    public function list(Request $request)
    {
        $source = $request->source;
        if(!$this->channelsBackend->isValidDevice($source)) {
            throw new Exception('Invalid source detected.');
        }

        $channels = $this->channelsBackend->getScannedChannels($source);

        $existingChannels = DvrChannel::pluck('mapped_channel_number', 'guide_number');

        foreach($channels as &$ch) {
            if(($mapped = $existingChannels->get($ch->GuideNumber)) !== false) {
                // existing channel number in db
                $ch->mapped_channel_number = $mapped;
            }
            else {
                // new channel number for db
                $ch->mapped_channel_number = $ch->GuideNumber;
            }

        }

        return view('channels.map',
            [
                'channels' => $channels,
                'source' => $source,
                'sources' => $this->channelsBackend->getDevices(),
                'channelsBackendUrl' => $this->channelsBackend->getBaseUrl(),
            ]
        );

    }

    public function map(Request $request)
    {
        $source = $request->source;
        if(!$this->channelsBackend->isValidDevice($source)) {
            throw new Exception('Invalid source detected.');
        }

        $channelMaps = $request->except('_token');

        $data = [];
        foreach($channelMaps as $guideNumberIdx => $mappedNumber) {
            list(,,,$guideNumber) = explode('_', $guideNumberIdx);
            $data[] = [
                'guide_number' => $guideNumber,
                'mapped_channel_number' => $mappedNumber ?? $guideNumber,
            ];
        }

        DvrChannel::upsert(
            $data,
            [ 'guide_number' ],
            [ 'mapped_channel_number' ]
        );

        return redirect(route('getChannelMapUI', ['source' => $source]));

    }

    public function playlist(Request $request)
    {
        $source = $request->source;
        if(!$this->channelsBackend->isValidDevice($source)) {
            throw new Exception('Invalid source detected.');
        }

        $scannedChannels = collect($this->channelsBackend->getScannedChannels($source));
        $existingChannels = DvrChannel::pluck('mapped_channel_number', 'guide_number');

        $scannedChannels->map(function($channel) use ($source, $existingChannels) {
            $channel->mappedChannelNum =
                $existingChannels->get($channel->GuideNumber) ?? $channel->GuideNumber;
        });

        return view('channels.playlist.full', [
            'scannedChannels' => $scannedChannels,
            'channelsBackendUrl' => $this->channelsBackend->getBaseUrl(),
            'source' => $source,
        ]);

    }

    public function xmltv(Request $request)
    {
        $source = $request->source;
        if(!$this->channelsBackend->isValidDevice($source)) {
            throw new Exception('Invalid source detected.');
        }

        $guideDays = env('CHANNELS_GUIDE_DAYS', config('channels.max_guide_days'));
        if($guideDays > config('channels.max_guide_days')) {
            $guideDays = config('channels.max_guide_days');
        }

        $guideChunkDuration =
            env('CHANNELS_GUIDE_DURATION', config('channels.max_guide_chunk_seconds'));

        if($guideChunkDuration > config('channels.max_guide_chunk_seconds')) {
            $guideChunkDuration = config('channels.max_guide_chunk_seconds');
        }

        $endGuideTime = Carbon::now()->addDays($guideDays);
        $guideTime = Carbon::now();

        $existingChannels = DvrChannel::pluck('guide_number', 'mapped_channel_number');

        while($guideTime < $endGuideTime) {

            $guideData = $this->channelsBackend
                ->getGuideData($source, $guideTime->timestamp, $guideChunkDuration);

            foreach($guideData as &$data) {
                $channelId =
                    $existingChannels->search($data->Channel->Number) ?? $data->Channel->Number;

                $data->Channel->channelId = $channelId;

                $data->Channel->displayNames = [
                    sprintf("%s %s", $data->Channel->channelId, $data->Channel->Name),
                    $data->Channel->channelId,
                    $data->Channel->Name,
                ];

                if(isset($data->Channel->Station)) {
                    $data->Channel->displayNames[] = sprintf(
                        "%s %s %s",
                        $data->Channel->channelId,
                        $data->Channel->Name,
                        $data->Channel->Station);

                }

                foreach($data->Airings as &$air) {

                    $air->startTime =
                        Carbon::createFromTimestamp($air->Time, 'America/Los_Angeles');
                    $air->endTime = $air->startTime->copy()->addSeconds($air->Duration);

                    $air->channelId = $channelId;

                }

            }

            echo view('channels.xmltv.full', ['guideData' => $guideData]);
            $guideTime->addSeconds($guideChunkDuration);

        }

    }
}
