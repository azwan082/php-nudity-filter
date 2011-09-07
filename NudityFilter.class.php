<?php

class NudityFilter {

    var $file, $last_from, $last_to;
    var $pixel_map, $merge_regions, $detected_regions;

    /**
     * @return bool True if it is nude picture
     */
    function check($file) {
        $this->file = $file;
        $this->last_from = -1;
        $this->last_to = -1;
        $this->pixel_map = array();
        $this->merge_regions = array();
        $this->detected_regions = array();
        // get image info
        $start = microtime(true);
        $img_info = getimagesize($this->file);
        if ($img_info === false) {
            echo $this->file.' is not an image file';
            return false;
        }
        $img_w = $img_info[0];
        $img_h = $img_info[1];
        switch ($img_info[2]) {
            case IMAGETYPE_GIF:
                $img_type = 'gif';
                $img = imagecreatefromgif($this->file);
                break;
            case IMAGETYPE_JPEG:
                $img_type = 'jpg';
                $img = imagecreatefromjpeg($this->file);
                break;
            case IMAGETYPE_PNG:
                $img_type = 'png';
                $img = imagecreatefrompng($this->file);
                break;
            default:
                echo 'Unsupported image type';
                return false;
        }
        if ($img === false) {
            echo 'Failed to read image file';
            return false;
        }
        // iterate image from top left to bottom right
        $x = 0;
        $y = 0;
        $i = 0;
        while ($y < $img_h) {
            while ($x < $img_w) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                //$check_pixels = array();
                $skin_px = false;
                //echo '$pixel_map['.$i.']<br>';
                if ($this->classify_skin($r, $g, $b)) {
                    $this->pixel_map[$i] = array(
                        'skin' => true,
                        'region' => 0,
                        'x' => $x,
                        'y' => $y
                    );
                    $region = -1;
                    $check_pixels = array($i-1, ($i-$img_w)-1, $i-$img_w, ($i-$img_w)+1); // left, above left, above, above right pixel relative to current pixel
                    foreach ($check_pixels as $cpx) {
                        if (isset($this->pixel_map[$cpx]) && $this->pixel_map[$cpx]['skin']) {
                            if ($this->pixel_map[$cpx]['region'] != $region && $region != -1 && $this->last_from != $region && $this->last_to != $this->pixel_map[$cpx]['region']) {
                                $this->merge($region, $this->pixel_map[$cpx]['region']);
                            }
                            $region = $this->pixel_map[$cpx]['region'];
                            $skin_px = true;
                        }
                    }
                    if ($skin_px) {
                        if ($region > -1) {
                            if (!isset($this->detected_regions[$region])) {
                                $this->detected_regions[$region] = array();
                            }
                            $this->pixel_map[$i]['region'] = $region;
                            $this->detected_regions[$region][] = $this->pixel_map[$i];
                        }
                    } else {
                        $this->pixel_map[$i-1]['region'] = count($this->detected_regions);
                        $this->detected_regions[] = $this->pixel_map[$i];
                    }
                } else {
                    $this->pixel_map[$i] = array(
                        'skin' => false,
                        'region' => 0,
                        'x' => $x,
                        'y' => $y
                    );
                }
                var_dump($i, $this->pixel_map[$i]);echo '<br>';
                $x++;
                $i++;
            }
            $x = 0;
            $y++;
        }
        echo 'Processed in '.number_format(microtime(true) - $start, 4). ' secs';
    }

    private function classify_skin($r, $g, $b) {
        $rgb_classifier = (($r>95) && ($g>40 && $g <100) && ($b>20) && ((max($r,$g,$b) - min($r,$g,$b)) > 15) && (abs($r-$g)>15) && ($r > $g) && ($r > $b));
        // normalize rgb
        $sum = $r+$g+$b;
        $nr = $r/$sum;
        $ng = $g/$sum;
        if ($ng != 0) { // avoid div by zero
            $nr_ng = ($nr/$ng);
        } else {
            $nr_ng = 0;
        }
        $norm_rgb_classifier = (($nr_ng>1.185) && ((($r*$b)/(pow($r+$g+$b,2))) > 0.107) && ((($r*$g)/(pow($r+$g+$b,2))) > 0.112));
        // to hsv
        list($h, $s) = $this->to_hsv($r, $g, $b);
        $hsv_classifier = ($h > 0 && $h < 35 && $s > 0.23 && $s < 0.68);
        return ($rgb_classifier || $norm_rgb_classifier || $hsv_classifier);
    }

    private function to_hsv($r, $g, $b) {
        $h = 0;
        $mx = max($r, $g, $b);
        $mn = min($r, $g, $b);
        $df = $mx - $mn;
        if ($df != 0) { // avoid div by zero
            if ($mx == $r) {
                $h = ($g - $b)/$df;
            }
            else if ($mx == $g) {
                $h = 2+(($g - $r)/$df);
            }
            else {
                $h = 4+(($r - $g)/$df);
            }
        } else {
            $h = 0;
        }
        $h = $h * 60;
        if ($h < 0) {
            $h = $h+360;
        }
        return array( $h, 1-(3*((min($r,$g,$b))/($r+$g+$b))), /*(1/3)*($r+$g+$b)*/ );
    }

    private function merge($from, $to) {
        $this->last_from = $from;
        $this->last_to = $to;
        $from_idx = -1;
        $to_idx = -1;
        foreach ($this->merge_regions as $k => $mreg) {
            if (in_array($from, $mreg)) {
                $from_idx = $k;
            }
            if (in_array($to, $mreg)) {
                $to_idx = $k;
            }
        }
        if ($from_idx != -1 && $to_idx != -1 && $from_idx == $to_idx) {
            return;
        }
        if ($from_idx == -1 && $to_idx == -1) {
            $this->merge_regions[] = array($from, $to);
            return;
        }
        if ($from_idx != -1 && $to_idx == -1) {
            $this->merge_regions[$from_idx][] = $to;
            return;
        }
        if ($from_idx == -1 && $to_idx != -1) {
            $this->merge_regions[$to_idx][] = $from;
            return;
        }
        if ($from_idx != -1 && $to_idx != -1 && $from_idx != $to_idx) {
            $this->merge_regions[$from_idx] = array_merge($this->merge_regions[$from_idx], $this->merge_regions[$to_idx]);
            $this->merge_regions[$to_idx] = array(); // just set to empty array, to keep array key counter
            return;
        }
    }
}

?>
