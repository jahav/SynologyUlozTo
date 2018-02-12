<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WaveTest extends TestCase
{
     public function testDownsampleForEven()
     {
        $wave = new Wave(array(0.2, 0.6, -0.2, 0.5, 1.0));
        $upsampled = $wave->Downsample(2);
        $expected = array(0.4, 0.15);
        $this->assertEquals($expected, $upsampled->GetSamples());
    }

    public function testUpsampleForEven()
    {
        $wave = new Wave(array(0.2, 0.6, -0.2));
        $upsampled = $wave->Upsample(2);
        $expected = array(0.2, 0.4, 0.6, 0.2, -0.2, -0.2);
        $this->assertEquals($expected, $upsampled->GetSamples());
    }

    // TODO: I am not really sure about this high pass filter, I would go with opposite sign
    public function testHighPass() {
        $wave = new Wave(array(-1, 1, 0.4, -0.7));
        $highPass = $wave->HighPass();
        $expected = array(-1, 0.3, 0.55, 0);
        $this->assertEquals($expected, $highPass->GetSamples());
    }

    public function testRectify() {
        $wave = new Wave(array(-1, 0.4, 1, -0.1, 0));
        $highPass = $wave->Rectify();
        $expected = array(1, 0.4, 1, 0.1, 0);
        $this->assertEquals($expected, $highPass->GetSamples());
    }

    public function testEnvelopeResponsivness() {
        $src = array(-4, 12, -16);
        $wave = new Wave($src);
        $resp = 0.25;
        $envelope = $wave->Envelope($resp, 0.0);
        $env0 = 0.0 * (1-$resp) + abs($src[0])*$resp;
        $env1 = $env0*(1-$resp) + abs($src[1])*$resp;
        $env2 = $env1*(1-$resp) + abs($src[2])*$resp;
        $expected = array($env0, $env1, $env2);
        $this->assertEquals($expected, $envelope->GetSamples());
    }

    public function testEnvelopeDampening() {
        $src = array(256,255,63,15, 5);
        $wave = new Wave($src);
        $resp = 1;
        $damp = 0.25;
        $envelope = $wave->Envelope($resp, $damp);
        $expected = array(256, 64, 16, 4, 5);
        $this->assertEquals($expected, $envelope->GetSamples());
    }

    public function testTrigger() {
        $wave = new Wave(array(0.5, 0.4, 0.3, 0.2, -0.3, -0.2, 0, 0.7));
        $envelope = $wave->Trigger(-0.1, 0.4);
        $expected = array(1, 1, 1, 1, 0, 0, 0, 1);
        $this->assertEquals($expected, $envelope->GetSamples());
    }
}

class Wave {
    private const WAV_HEADER_SIZE = 48;
    /** How fast will envelope respond to increase in the sample value */
    private const DEFAULT_ENVELOPE_RESPONSIVENESS = 0.2;
    private const DEFAULT_ENVELOPE_DAMPENING = 0.995;
    private $Samples;

    public function __construct($Samples = null) {
        $this->Samples = $Samples;    
    }

    public function GetMax() {
        return max($this->Samples);
    }

    public function Load($Url) {
        $this->Url = $Url;

        $ch = curl_init($Url);
        try {
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            // TODO: It doesnt work with verification, solve
            curl_setopt($ch, CURLOPT_SSL_VERIFYSTATUS, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http_code != 200) {
                throw new Exception('Unable to get file "'.$Url.'", got status: '.$http_code);
            }
            
            if (curl_errno($ch) != 0) {
                throw new Exception('cURL error: '.curl_error($ch));
            }
        } finally {
            curl_close($ch);
        }

        // TODO: Why in original extra -4
        $sample_data = substr($response, self::WAV_HEADER_SIZE, (strlen($response)-self::WAV_HEADER_SIZE));
        $this->Samples = array();

        for ($i = 0; $i<strlen($sample_data); $i++) {
            $val = $sample_data[$i];
            $this->Samples[$i] = (ord($val) - 128) / 128;
        }
    }

    public function  Downsample(int $samples) {
        $downsamples = array();
        $count = floor(count($this->Samples) / $samples);
        for ($tgt_i = 0; $tgt_i < $count; $tgt_i++) {
            $src_start = $tgt_i * $samples;
            $total = 0;
            for ($i = $src_start; $i <  $src_start + $samples; $i++) {
                $total += $this->Samples[$i];
            }
            $downsamples[$tgt_i] = $total / $samples;
        }
        
        return new Wave($downsamples);
    }

    public function Upsample(int $samples) {
        $upsampled = array();
        for ($src_i = 0; $src_i < count($this->Samples); $src_i++) {
            $src_val_start = $this->Samples[$src_i];
            $src_val_end = $this->Samples[min($src_i + 1, count($this->Samples) - 1)];

            $tgt_start = $src_i * $samples;
            $tgt_end = $tgt_start + $samples;
            $coef = 0;
            for ($tgt_i = $tgt_start; $tgt_i < $tgt_end; $tgt_i++) {
                $upsampled[$tgt_i] = $src_val_start + ($src_val_end - $src_val_start) * $coef;
                $coef += floatval(1)/$samples;
            }
        }

        return new Wave($upsampled);
    }

    public function GetSamples() {
        return $this->Samples;
    }

    public function GetIntervals() {
        $waveLowPass = LowPass();
        $waveHighPass = HighPass();
    }

    private function LowPass() {
        return $this->Downsample(8)->Upsample(8);
    }

    public function HighPass() {
        $highPass = array();
        for ($i = 0; $i < count($this->Samples) - 1; $i++) {
            $highPass[$i] = ($this->Samples[$i] - $this->Samples[$i+1])/2;
        }
        $highPass[count($this->Samples) - 1] = 0;
        return new Wave($highPass);
    }

    public function Rectify() {
        return new Wave(array_map(function ($sample) { return abs($sample); }, $this->Samples));
    }

    public function Envelope(float $responsivness = ENVELOPE_RESPONSIVENESS, float $dampening = DEFAULT_ENVELOPE_DAMPENING) {
        $envelope = array();
        $envelopeValue = 0;
        for ($i = 0; $i < count($this->Samples); $i++) {
            $sampleValue = abs($this->Samples[$i]);
            if ($sampleValue > $envelopeValue) {
                $envelopeValue = (1-$responsivness) * $envelopeValue + $responsivness * $sampleValue;
            } else {
                $envelopeValue *= $dampening;
            }
            $envelope[$i] = $envelopeValue;
        }
        return new Wave($envelope);
    }

    public function Trigger(float $low, float $high)
    {
        $triggers = array();
        $trigger = 0.0;
        
        for ($i = 0; $i < count($this->Samples); $i++) {
            $sampleValue = $this->Samples[$i];
            if ($sampleValue > $high) {
                $trigger = 1.0;
            }
            if ($sampleValue < $low) {
                $trigger = 0.0;
            }
            $triggers[$i] = $trigger;
        }

        return new Wave($triggers);
    }
}

?>