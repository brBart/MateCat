<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 1/26/16
 * Time: 2:32 PM
 */

namespace Features\ReviewImproved\Controller\API;

use API\V2\Validators\ChunkPasswordValidator;
use API\V2\KleinController;
use Chunks_ChunkStruct;
use Projects_ProjectStruct;
use API\V2\Json\QALocalWarning;
use Features\ReviewImproved\Model\ArchivedQualityReportDao;
use Features\ReviewImproved\Model\QualityReportModel ;
use CatUtils;

class QualityReportController extends KleinController
{

    /**
     * @var Chunks_ChunkStruct
     */
    protected $chunk;

    /**
     * @var Projects_ProjectStruct
     */
    protected $project;

    /**
     * @param Chunks_ChunkStruct $chunk
     *
     * @return $this
     */
    public function setChunk( $chunk ) {
        $this->chunk = $chunk;

        return $this;
    }

    private $model ;

    public function show() {
        $this->model = new QualityReportModel( $this->chunk );
        $this->model->setDateFormat('c');

        $this->response->json( array(
                'quality-report' => $this->model->getStructure()
        ));
    }

    private function getOptionalQueryFields() {
        $feature = $this->chunk->getProject()->isFeatureEnabled('translation_versions');
        $options = array();

        if ( $feature ) {
            $options['optional_fields'] = array('st.version_number');
        }

        $options['optional_fields'][] = "st.suggestion_source";
        $options['optional_fields'][] = "st.suggestion";
        $options['optional_fields'][] = "st.edit_distance";
        $options['optional_fields'][] = "st.locked";
        $options['optional_fields'][] = "st.match_type";


        $options = $this->featureSet->filter('filter_get_segments_optional_fields', $options);

        return $options;
    }

    public function segments() {

        $this->project    = $this->chunk->getProject();

        $this->featureSet->loadForProject( $this->project ) ;

        $lang_handler = \Langs_Languages::getInstance();

        if ($this->ref_segment == '') {
            $this->ref_segment = 0;
        }


        $data = getMoreSegments(
                $this->chunk->id, $this->chunk->password, 50,
                $this->ref_segment, "after",
                $this->getOptionalQueryFields()
        );

        foreach ($data as $i => $seg) {

            $id_file = $seg['id_file'];

            if ( !isset($this->data["$id_file"]) ) {
                $this->data["$id_file"]['jid'] = $seg['jid'];
                $this->data["$id_file"]["filename"] = \ZipArchiveExtended::getFileName($seg['filename']);
                $this->data["$id_file"]["mime_type"] = $seg['mime_type'];
                $this->data["$id_file"]['source'] = $lang_handler->getLocalizedName($seg['source']);
                $this->data["$id_file"]['target'] = $lang_handler->getLocalizedName($seg['target']);
                $this->data["$id_file"]['source_code'] = $seg['source'];
                $this->data["$id_file"]['target_code'] = $seg['target'];
                $this->data["$id_file"]['segments'] = array();
            }

            $seg = $this->featureSet->filter('filter_get_segments_segment_data', $seg) ;

            $qr_struct = new \QualityReport_QualityReportSegmentStruct($seg);

            $seg['warnings'] = $qr_struct->getLocalWarning();
//            $seg['pee'] = $qr_struct->getPEE();
//            $seg['ice_modified'] = $qr_struct->isICEModified();
            $seg['secs_per_word'] = $qr_struct->getSecsPerWord();

            unset($seg['id_file']);
            unset($seg['source']);
            unset($seg['target']);
            unset($seg['source_code']);
            unset($seg['target_code']);
            unset($seg['mime_type']);
            unset($seg['filename']);
            unset($seg['jid']);
            unset($seg['pid']);
            unset($seg['cid']);
            unset($seg['tid']);
            unset($seg['pname']);
            unset($seg['create_date']);
            unset($seg['id_segment_end']);
            unset($seg['id_segment_start']);
            unset($seg['serialized_errors_list']);

            $seg['parsed_time_to_edit'] = CatUtils::parse_time_to_edit($seg['time_to_edit']);

            ( $seg['source_chunk_lengths'] === null ? $seg['source_chunk_lengths'] = '[]' : null );
            ( $seg['target_chunk_lengths'] === null ? $seg['target_chunk_lengths'] = '{"len":[0],"statuses":["DRAFT"]}' : null );
            $seg['source_chunk_lengths'] = json_decode( $seg['source_chunk_lengths'], true );
            $seg['target_chunk_lengths'] = json_decode( $seg['target_chunk_lengths'], true );

            $seg['segment'] = CatUtils::rawxliff2view( CatUtils::reApplySegmentSplit(
                    $seg['segment'] , $seg['source_chunk_lengths'] )
            );

            $seg['translation'] = CatUtils::rawxliff2view( CatUtils::reApplySegmentSplit(
                    $seg['translation'] , $seg['target_chunk_lengths'][ 'len' ] )
            );

            $this->data["$id_file"]['segments'][] = $seg;

        }

        $this->result['data']['files'] = $this->data;

        //$this->result['data']['where'] = $this->where;
        $this->response->json($this->result);
    }



    public function versions() {
        $dao = new ArchivedQualityReportDao();
        $versions = $dao->getAllByChunk( $this->chunk ) ;
        $response = array();

        foreach( $versions as $version ) {
            $response[] = array(
                    'id' => (int) $version->id,
                    'version_number' => (int) $version->version,
                    'created_at' => \Utils::api_timestamp( $version->create_date ),
                    'quality-report' => json_decode( $version->quality_report )
            ) ;
        }

        $this->response->json( array('versions' => $response ) ) ;

    }

    protected function afterConstruct() {
        $Validator = new ChunkPasswordValidator( $this ) ;
        $Controller = $this;
        $Validator->onSuccess( function () use ( $Validator, $Controller ) {
            $Controller->setChunk( $Validator->getChunk() );
        } );
        $this->appendValidator( $Validator );
    }

}