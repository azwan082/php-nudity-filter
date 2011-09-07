<?php

class NudityFilter {

    var $file, $img_w, $img_h, $last_from, $last_to, $pixel_map, $merge_regions,
        $detected_regions, $det_regions, $skin_regions;

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
        //echo "image dim: $img_w x $img_h<br>total pixels: ".($img_w * $img_h)."<br>";
        while ($y < $this->img_h) {
            while ($x < $this->img_w) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $skin_px = false;
//                echo '$pixel_map['.$i.'] = ('.$x.', '.$y.')<br>';
                if ($this->classify_skin($r, $g, $b)) {
                    $this->pixel_map[$i] = array(
                        'id' => $i,
                        'skin' => true,
                        'region' => 0,
                        'x' => $x,
                        'y' => $y
                    );
                    $region = -1;
                    $check_pixels = array($i-1, ($i-$this->img_w)-1, $i-$this->img_w, ($i-$this->img_w)+1); // left, above left, above, above right pixel relative to current pixel
//                    echo '&nbsp;&nbsp;&nbsp;&nbsp;';
//                    echo '$check_pixels: array(';foreach($check_pixels as $chk)echo $chk.',';echo ')<br>';
                    foreach ($check_pixels as $cpx) {
                        if (isset($this->pixel_map[$cpx]) && $this->pixel_map[$cpx]['skin']) {
//                            echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
//                            echo 'pixel_map['.$cpx.'][skin]='.$this->pixel_map[$cpx]['skin'].', ';
//                            echo 'pixel_map['.$cpx.'][region]='.$this->pixel_map[$cpx]['region'].', ';
//                            echo 'region='.$region.', ';
//                            echo 'last_from='.$this->last_from.', ';
//                            echo 'last_to='.$this->last_to.'<br>';
                            if ($this->pixel_map[$cpx]['region'] != $region && $region != -1 && $this->last_from != $region && $this->last_to != $this->pixel_map[$cpx]['region']) {
                                $this->add_merge_region($region, $this->pixel_map[$cpx]['region']);
                            }
                            $region = $this->pixel_map[$cpx]['region'];
                            $skin_px = true;
                        }
                    }
//                    echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
                    if ($skin_px) {
                        if ($region > -1) {
                            if (!isset($this->detected_regions[$region])) {
                                $this->detected_regions[$region] = array();
                            }
                            $this->pixel_map[$i]['region'] = $region;
                            $this->detected_regions[$region][] = $this->pixel_map[$i];
//                            echo 'skin_px=True, pixel_map['.$i.'], pushed to detected_regions['.$region.']<br>';
                        }
                    } else {
                        $this->pixel_map[$i]['region'] = count($this->detected_regions);
                        $this->detected_regions[] = array($this->pixel_map[$i]);
//                        echo 'skin_px=False, pixel_map['.$i.'], pushed as new item to detected_regions (index: '.(count($this->detected_regions)-1).')<br>';
                    }
                } else {
                    $this->pixel_map[$i] = array(
                        'id' => $i,
                        'skin' => false,
                        'region' => 0,
                        'x' => $x,
                        'y' => $y
                    );
                }
                $x++;
                $i++;
            }
            $x = 0;
            $y++;
        }
        $this->merge_and_clear();
        // <!-- TEST
//        echo 'det_regions:<br>';
//        foreach ($this->det_regions as $m => $dt) {
//            echo $m.' =><br>';//var_dump($dt);
//            foreach ($dt as $d => $t) {
//                echo '&nbsp;&nbsp;&nbsp;&nbsp;'.$d.' => ';var_dump($t);echo '<br>';
////                foreach ($t as $_d => $_t) {
////                    echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'.$_d.' => ';var_dump($_t);echo '<br>';
////                }
////                echo '<br>';
//            }
//            echo'<br>';
//        }
//        echo 'merge_regions:<br>';
//        foreach ($this->merge_regions as $i => $mr) {
//            echo $i.' => ';var_dump($mr);echo '<br>';
//        }
//        echo 'detected_regions:<br>';
//        foreach ($this->detected_regions as $j => $dr) {
//            //echo $j.' => '.gettype($dr).' ('.count($dr).')<br>';
//            echo $j.' => ';var_dump($dr);echo ')<br>';
//        }
//        echo 'skin_regions: array('.count($this->skin_regions).')<br>';
//        foreach ($this->skin_regions as $j => $sr) {
//            echo $j.' => array('.count($sr).')<br>';
//            foreach ($sr as $s => $r) {
//                echo '&nbsp;&nbsp;&nbsp;&nbsp;'.$s.' => ';var_dump($r);echo '<br>';
//            }
//            echo'<br>';
//        }
//        echo 'pixel_map:<br>';
//        foreach ($this->pixel_map as $j => $px) {
//            echo $j.' => ';var_dump($px);echo'<br>';
//        }
        echo 'Processed in '.number_format(microtime(true) - $start, 4). ' secs';
        // TEST -->
        return $this->analyze_regions();
    }

    private function classify_skin($r, $g, $b) {
        $rgb_classifier = (($r>95) && ($g>40 && $g <100) && ($b>20) && ((max($r,$g,$b) - min($r,$g,$b)) > 15) && (abs($r-$g)>15) && ($r > $g) && ($r > $b));
        // normalize rgb
        $sum = $r+$g+$b;
        $nr = $this->div($r,$sum);
        $ng = $this->div($g,$sum);
//        if ($ng != 0) { // avoid div by zero
//            $nr_ng = ($nr/$ng);
//        } else {
//            // in JS, div by zero is Infinity, so the value is large than any number ($nr_ng>1.185 is always true)
//            // here we set to a value that later will make sure the logic will return true ($nr_ng>1.185)
//            $nr_ng = 2;
//        }
        $norm_rgb_classifier = (($this->div($nr,$ng)>1.185) && ($this->div(($r*$b),(pow($r+$g+$b,2))) > 0.107) && ($this->div(($r*$g),(pow($r+$g+$b,2))) > 0.112));
        // to hsv
        list($h, $s) = $this->to_hsv($r, $g, $b);
        $hsv_classifier = ($h > 0 && $h < 35 && $s > 0.23 && $s < 0.68);
        return ($rgb_classifier || $norm_rgb_classifier || $hsv_classifier);
    }

//    private function _to_hsv($r, $g, $b) {
//        $h = acos((0.5*(($r-$g)+($r-$b)))/(sqrt((pow(($r-$g),2)+(($r-$b)*($g-$b))))));
//        $s = 1-(3*((min($r,$g,$b))/($r+$g+$b)));
//        $v = (1/3)*($r+$g+$b);
//        return array($h, $s, $v);
//    }

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
        foreach ($this->merge_regions as $k => $mreg) {
            if (in_array($from, $mreg)) {
                $from_idx = $k;
            }
            if (in_array($to, $mreg)) {
                $to_idx = $k;
            }
        }
        // cannot merge same region (in same $this->merge_regions[$k])
        if ($from_idx != -1 && $to_idx != -1 && $from_idx == $to_idx) {
            return;
        }
//        echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        // no element inside $this->merge_regions
        if ($from_idx == -1 && $to_idx == -1) {
            $this->merge_regions[] = array($from, $to); // add new element (array element) to $this->merge_regions array
//            echo 'array($from,$to) pushed as new element to merge_regions (index: '.(count($this->merge_regions)-1).'), from_idx='.$from_idx.', to_idx='.$to_idx.', from='.$from.', to='.$to.'<br>';
            return;
        }
        // $from exists in $this->merge_regions
        if ($from_idx != -1 && $to_idx == -1) {
            $this->merge_regions[$from_idx][] = $to; // add new element to an array element (identified by $from_idx) inside $this->merge_regions array
//            echo '$to appended to merge_regions['.$from_idx.'], from_idx='.$from_idx.', to_idx='.$to_idx.', from='.$from.', to='.$to.'<br>';
            return;
        }
        // $to exists in $this->merge_regions
        if ($from_idx == -1 && $to_idx != -1) {
            $this->merge_regions[$to_idx][] = $from;
//            echo '$from appended to merge_regions['.$to_idx.'], from_idx='.$from_idx.', to_idx='.$to_idx.', from='.$from.', to='.$to.'<br>';
            return;
        }
        // both $to and $from exists, merge them into $from, then empty $this->merge_regions[$to_idx]
        if ($from_idx != -1 && $to_idx != -1 && $from_idx != $to_idx) {
            $this->merge_regions[$from_idx] = array_merge($this->merge_regions[$from_idx], $this->merge_regions[$to_idx]);
            $this->merge_regions[$to_idx] = array(); // just set to empty array, to keep array key counter
//            unset($this->merge_regions[$to_idx]);
//            echo 'array_merge merge_regions['.$from_idx.'] and merge_regions['.$to_idx.'], then empty merge_regions['.$to_idx.'], from_idx='.$from_idx.', to_idx='.$to_idx.', from='.$from.', to='.$to.'<br>';
            return;
        }
    }

    private function merge_and_clear() {
        $this->det_regions = array();
        $this->skin_regions = array();
        foreach ($this->merge_regions as $i => $mr) {
            if (!isset($this->det_regions[$i])) {
                $this->det_regions[$i] = array();
            }
            foreach ($mr as $m) {
                $this->det_regions[$i] = array_merge($this->det_regions[$i], $this->detected_regions[$m]);
                $this->detected_regions[$m] = array();
//                unset($this->detected_regions[$m]); // error: max execution time 60 secs
            }
        }
        if (!empty($this->detected_regions)) {
            foreach ($this->detected_regions as $dr) {
                $this->det_regions[] = $dr;
            }
        }
        // only pushes regions which are bigger than a specific amount to the final result
        foreach ($this->det_regions as $dt) {
            if (count($dt) > 30) {
                $this->skin_regions[] = $dt;
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
        function bsort($a,$b){
            return count($b)-count($a);
        }
        // sort the detected regions by size
        usort($this->skin_regions, 'bsort');
        $total_pixel = $this->img_w * $this->img_h;
        $total_skin = 0;
        // count total skin pixels
        foreach ($this->skin_regions as $sr) {
            $total_skin += count($sr);
        }
        // check if there are more than 15% skin pixel in the image
        if (($total_skin/$total_pixel)*100 < 15) {
            return false;
        }
        // check if the largest skin region is less than 35% of the total skin count
        // AND if the second largest region is less than 30% of the total skin count
        // AND if the third largest region is less than 30% of the total skin count
        if ((count($this->skin_regions[0])/$total_skin)*100 < 35
            && (count($this->skin_regions[1])/$total_skin)*100 < 30
            && (count($this->skin_regions[2])/$total_skin)*100 < 30) {
            return false;
        }
        // check if the number of skin pixels in the largest region is less than 45% of the total skin count
        if ((count($this->skin_regions[0])/$total_skin)*100 < 45) {
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
