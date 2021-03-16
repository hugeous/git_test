관리자 폴더에 대한 안내 사항

1. 중앙관리 폴더
- 해당 폴더에는 고도에서 중앙관리 하는 "gd_share" 라는 폴더가 있습니다.
- 해당 폴더는 FTP 접속을 해도 보이지 않으며, 접근이 되지 않으며 수정을 하실 수 없습니다.
- 해당 폴더 및 폴더 안의 .js, .css, 이미지 화일은 패치시 자동 패치가 됩니다.

2. 공용 폴더 및 화일
- script, css 에는 공통적으로 선언되어 있는 /script/admin-custom.js 와 /css/admin-custom.css가 있습니다.
- 추가적인 script, css는 해당 화일을 이용하게 되면 head.php 를 수정하지 않아도 자동으로 불러와 집니다
 (head.php 화일에 두개의 화일을 이미 로드 하고 있습니다.)
- 이외 추가적인 script, css 를 이용시 각각 업로드 후에 head.php에 추가해서 사용하시거나 Controller에서 직접 이용하시기 바랍니다.
- Controller에서 이용시 index Methods 에
$this->addCss([배열]); // css 화일명을 배열로 ('../../css/test1.css','../../css/test1.css')
$this->addScript([배열]); // js 화일명을 배열로 ('../../script/test1.js','../../script/test1.js')
와 같은 방식으로 이용을 하실 수 있습니다.

- 작성자 : NHN godo: 고도몰5 개발팀
- 작성일 : 2016-07-11