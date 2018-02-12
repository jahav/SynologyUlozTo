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
 }

class Wave {
    private const WAV_HEADER_SIZE = 48;

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
        
        $ret =  new Wave();
        $ret->Samples = $downsamples;
        return $ret;
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

        $ret =  new Wave();
        $ret->Samples = $upsampled;
        return $ret;
    }

    public function GetSamples() {
        return $this->Samples;
    }
}

?>