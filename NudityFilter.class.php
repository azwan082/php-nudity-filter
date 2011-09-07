<?php

class NudityFilter {

    var $file, // full path of image file
        $filename, // image file name
        $img_w, // image width
        $img_h, // image height
        $last_from, // previous `from` region number
        $last_to, // previous `to` region number
        $pixel_map, // array of skin pixel holding region number
        $merge_regions, // array of pixel number which are merged in a region
        $detected_regions, // array of skin pixel for arranging region number
        $skin_regions, // array of skin regions, store number of pixels in a region
        $error, // last error message
        $log; // array of log message

    /**
     * @return bool True if it is nude picture
     */
    function check($file) {
        $this->file = $file;
        $this->reset_var();
        // get image info
        $start = microtime(true);
        $img_info = getimagesize($this->file);
        if ($img_info === false) {
            $this->error = $this->filename .' is not an image file';
            return false;
        }
        $this->img_w = $img_info[0];
        $this->img_h = $img_info[1];
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
                $this->error = 'Unsupported image type ('. $this->filename . ')';
                return false;
        }
        if ($img === false) {
            $this->error = 'Failed to read image file ('. $this->filename .')';
            return false;
        }
        $this->log[] = 'Finish reading image file in '.number_format(microtime(true) - $start, 4). ' secs';
        // iterate image from top left to bottom right
        $x = 0;
        $y = 0;
        $i = 0;
        while ($y < $this->img_h) {
            while ($x < $this->img_w) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $skin_px = false;
                if ($this->classify_skin($r, $g, $b)) {
                    $this->pixel_map[$i] = 0; // pixel_map stores `region` value of the skin pixel
                    $region = -1;
                    // check neighbouring pixels for skin pixel, if one of them is skin pixel, all subsequent pixels will be labelled as skin pixel
                    // left, above left, above, above right pixel relative to current pixel (order of checking is important)
                    $check_pixels = array($i-1, ($i-$this->img_w)-1, $i-$this->img_w, ($i-$this->img_w)+1);
                    foreach ($check_pixels as $cpx) {
                        if (isset($this->pixel_map[$cpx])) {
                            if ($this->pixel_map[$cpx] != $region && $region != -1 && $this->last_from != $region && $this->last_to != $this->pixel_map[$cpx]) {
                                $this->add_merge_region($region, $this->pixel_map[$cpx]);
                            }
                            $region = $this->pixel_map[$cpx];
                            $skin_px = true;
                        }
                    }
                    if ($skin_px) {
                        // one of neighbouring pixel is skin pixel, so need to update current pixel region
                        // to that neighbour pixel region number
                        if ($region > -1) {
                            if (!isset($this->detected_regions[$region])) {
                                $this->detected_regions[$region] = array();
                            }
                            $this->pixel_map[$i] = $region;
                            $this->detected_regions[$region][] = $this->pixel_map[$i];
                        }
                    } else {
                        // append new region number to this pixel
                        $this->pixel_map[$i] = count($this->detected_regions);
                        $this->detected_regions[] = array($this->pixel_map[$i]);
                    }
                }
                $x++;
                $i++;
            }
            $x = 0;
            $y++;
        }
        $this->log[] = 'Finish reading all pixels in '.number_format(microtime(true) - $start, 4). ' secs';
        $this->merge_and_clear();
        $this->log[] = 'Finish merge_and_clear() in '.number_format(microtime(true) - $start, 4). ' secs';
        $result = $this->analyze_regions();
        $this->log[] = 'Process completed in '.number_format(microtime(true) - $start, 4). ' secs';
        return $result;
    }

    /**
     * Reset class variables for current checking process
     */
    private function reset_var() {
        $this->filename = basename($this->file);
        $this->last_from = -1;
        $this->last_to = -1;
        $this->pixel_map = array();
        $this->merge_regions = array();
        $this->detected_regions = array();
        $this->log = array();
        $this->error = '';
    }

    /**
     * Determine if current pixel is a skin pixel
     * @param int $r `red` color component
     * @param int $g `green` color component
     * @param int $b `blue` color component
     * @return bool True if this pixel is a skin pixel
     */
    private function classify_skin($r, $g, $b) {
        // A Survey on Pixel-Based Skin Color Detection Techniques
        $rgb_classifier = (($r>95) && ($g>40 && $g <100) && ($b>20) && ((max($r,$g,$b) - min($r,$g,$b)) > 15) && (abs($r-$g)>15) && ($r > $g) && ($r > $b));
        // normalize rgb
        $sum = $r+$g+$b;
        $nr = $this->div($r,$sum);
        $ng = $this->div($g,$sum);
        $norm_rgb_classifier = (($this->div($nr,$ng)>1.185) && ($this->div(($r*$b),(pow($r+$g+$b,2))) > 0.107) && ($this->div(($r*$g),(pow($r+$g+$b,2))) > 0.112));
        // to hsv
        list($h, $s) = $this->to_hsv($r, $g, $b);
        $hsv_classifier = ($h > 0 && $h < 35 && $s > 0.23 && $s < 0.68);
        return ($rgb_classifier || $norm_rgb_classifier || $hsv_classifier);
    }

    /**
     * Convert RGB value to HSV
     * @param int $r `red` color component
     * @param int $g `green` color component
     * @param int $b `blue` color component
     * @return array HSV component
     */
    private function to_hsv($r, $g, $b) {
        $h = 0;
        $mx = max($r, $g, $b);
        $mn = min($r, $g, $b);
        $df = $mx - $mn;
        if ($mx == $r) {
            $h = $this->div(($g - $b),$df);
        }
        else if ($mx == $g) {
            $h = 2+$this->div(($g - $r),$df);
        }
        else {
            $h = 4+$this->div(($r - $g),$df);
        }
        $h = $h * 60;
        if ($h < 0) {
            $h = $h+360;
        }
        return array( $h, 1-(3*$this->div((min($r,$g,$b)),($r+$g+$b))), (1/3)*($r+$g+$b) );
    }

    /**
     * when iterating from top left pixel to bottom right, some early pixels marked as skin pixel and some don't,
     * if skin pixel are not continuous, each skin pixels will be marked as new region (and the region number will increase),
     * but even if skin pixels that have only one pixel gap, will be treated as two different region
     * so add_merge_region() will merge skin & non-skin pixels that are near to each other and combine under one region
     * @param int $from
     * @param int $to
     * @return null
     */
    private function add_merge_region($from, $to) {
        $this->last_from = $from;
        $this->last_to = $to;
        $from_idx = -1;
        $to_idx = -1;
        foreach ($this->merge_regions as $k => $mr) {
            if (in_array($from, $mr)) {
                $from_idx = $k;
            }
            if (in_array($to, $mr)) {
                $to_idx = $k;
            }
        }
        // cannot merge same region (in same $this->merge_regions[$k])
        if ($from_idx != -1 && $to_idx != -1 && $from_idx == $to_idx) {
            return;
        }
        // no element inside $this->merge_regions
        if ($from_idx == -1 && $to_idx == -1) {
            $this->merge_regions[] = array($from, $to); // add new element (array element) to $this->merge_regions array
            return;
        }
        // $from exists in $this->merge_regions
        if ($from_idx != -1 && $to_idx == -1) {
            $this->merge_regions[$from_idx][] = $to; // add new element to an array element (identified by $from_idx) inside $this->merge_regions array
            return;
        }
        // $to exists in $this->merge_regions
        if ($from_idx == -1 && $to_idx != -1) {
            $this->merge_regions[$to_idx][] = $from;
            return;
        }
        // both $to and $from exists, merge them into $from, then empty $this->merge_regions[$to_idx]
        if ($from_idx != -1 && $to_idx != -1 && $from_idx != $to_idx) {
            $this->merge_regions[$from_idx] = array_merge($this->merge_regions[$from_idx], $this->merge_regions[$to_idx]);
            unset($this->merge_regions[$to_idx]);
            return;
        }
    }

    /**
     * Get merge_regions pixel data, get only regions of certain size and store to skin_regions
     * data in skin_regions will be used to determine if picture contains nudity or not
     */
    private function merge_and_clear() {
        $det_regions = array();
        $this->skin_regions = array();
        foreach ($this->merge_regions as $i => $mr) {
            if (!isset($det_regions[$i])) {
                $det_regions[$i] = array();
            }
            foreach ($mr as $m) {
                if (!empty($this->detected_regions[$m])) {
                    $det_regions[$i] = array_merge($det_regions[$i], $this->detected_regions[$m]);
                }
                unset($this->detected_regions[$m]);
            }
        }
        if (!empty($this->detected_regions)) {
            foreach ($this->detected_regions as $dr) {
                $det_regions[] = $dr;
            }
        }
        // only pushes regions which are bigger than a specific amount to the final result
        foreach ($det_regions as $dt) {
            $count_dt = count($dt);
            if ($count_dt > 30) {
                $this->skin_regions[] = $count_dt;
            }
        }
    }

    /**
     * analyze skin_regions based on criteria on the research paper
     * @return <type>
     */
    private function analyze_regions() {
        // if there are less than 3 regions
        if (count($this->skin_regions) < 3) {
            return false;
        }
        // sort the detected regions by size
        rsort($this->skin_regions);
        $total_pixel = $this->img_w * $this->img_h;
        $total_skin = array_sum($this->skin_regions);
        // check if there are more than 15% skin pixel in the image
        if (($total_skin/$total_pixel)*100 < 15) {
            return false;
        }
        // check if the largest skin region is less than 35% of the total skin count
        // AND if the second largest region is less than 30% of the total skin count
        // AND if the third largest region is less than 30% of the total skin count
        if (($this->skin_regions[0]/$total_skin)*100 < 35
            && ($this->skin_regions[1]/$total_skin)*100 < 30
            && ($this->skin_regions[2]/$total_skin)*100 < 30) {
            return false;
        }
        // check if the number of skin pixels in the largest region is less than 45% of the total skin count
        if (($this->skin_regions[0]/$total_skin)*100 < 45) {
            return false;
        }
        // @todo:
        // build the bounding polygon by the regions edge values:
        // Identify the leftmost, the uppermost, the rightmost, and the lowermost skin pixels of the three largest skin regions.
        // Use these points as the corner points of a bounding polygon.

        // @todo:
        // check if the total skin count is less than 30% of the total number of pixels
        // AND the number of skin pixels within the bounding polygon is less than 55% of the size of the polygon
        // if this condition is true, it's not nude.

        // @todo: include bounding polygon functionality

        // if there are more than 60 skin regions
        // @todo: the average intensity within the polygon is less than 0.25
        if (count($this->skin_regions) > 60){
            return false;
        }
        return true;
    }

    /**
     * Safe division operation, check for division by zero
     * @param int|float $a
     * @param int|float $b
     * @return int|float
     */
    private function div($a, $b) {
        if ($b == 0) {
            return 0;
        } else {
            return $a / $b;
        }
    }
}
?>
