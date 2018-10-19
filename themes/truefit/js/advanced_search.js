var nextGroup = 0;
var groupLength = [];

function addSearch(group, fieldValues)
{
  if(typeof fieldValues === "undefined") {
    fieldValues = {"term":"Enter your search term"};
  }
  // Build the new search
  var inputID = group+'_'+groupLength[group];
  var $newSearch = $($('#new_search_template').html());

  $newSearch.attr('id', 'search'+inputID);
  $newSearch.find('input.form-control')
    .attr('id', 'search_lookfor'+inputID)
    .attr('name', 'lookfor'+group+'[]');

  // the hidden type input
  $newSearch.find('input.type')
    .attr('id', 'search_type'+inputID)
    .attr('name', 'type'+group+'[]');
  // the button
  $newSearch.find('input.type').next()
    .attr('id', 'advSearchGroupType'+inputID)
    .attr('data-target', '#advSearchGroupType'+inputID+'Dropdown');
  // the dropdown
  $newSearch.find('input.type').next().next()
    .attr('id', 'advSearchGroupType'+inputID + 'Dropdown');
  // change the button to ...
  if( fieldValues.field ) {
    // ... the correct element in the dropdown
    $newSearch.find('input.type').next().next().find('button.btnTypeValue' + fieldValues.field).click();
  } else {
    // ... the first element in the dropdown
    $newSearch.find('input.type').next().next().children('li:first-child').children('button').click();
  }

  $newSearch.find('.close')
    .attr('onClick', 'deleteSearch('+group+','+groupLength[group]+'); return false;');
  // Preset Values
  if(typeof fieldValues.term !== "undefined") {
    $newSearch.find('input.form-control').attr('value', fieldValues.term);
  }
  if(typeof fieldValues.field !== "undefined") {
    $newSearch.find('select.type option[value="'+fieldValues.field+'"]').attr('selected', 1);
  }
  if (typeof fieldValues.op !== "undefined") {
    $newSearch.find('select.op option[value="'+fieldValues.op+'"]').attr('selected', 1);
  }
  // Insert it
  $("#group" + group + "Holder").before($newSearch);
  // Individual search ops (for searches like EDS)
  if (groupLength[group] == 0) {
    $newSearch.find('.first-op')
      .attr('name', 'op' + group + '[]')
      .removeClass('hidden');
    $newSearch.find('select.op').remove();
  } else {
    $newSearch.find('select.op')
      .attr('id', 'search_op' + group + '_' + groupLength[group])
      .attr('name', 'op' + group + '[]')
      .removeClass('hidden');
    $newSearch.find('.first-op').remove();
    $newSearch.find('label').remove();
    // Show x if we have more than one search inputs
    $('#group'+group+' .advSearchSearch .close').removeClass('hidden');
    $('#group'+group+' .advSearchSearch .close').next().css('padding-right','30px');
  }
  groupLength[group]++;

  setTimeout(adjustAdvSearchTypeButtons,20);
}

function deleteSearch(group, sindex)
{
  for(var i=sindex;i<groupLength[group]-1;i++) {
    var $search0 = $('#search'+group+'_'+i);
    var $search1 = $('#search'+group+'_'+(i+1));
    $search0.find('input.textBox').val($search1.find('input.textBox').val());
    $search0.find('input.type').val($search1.find('input.type').val());
    $search0.find('button.advSearchTypeButton span').html($search1.find('button.advSearchTypeButton span').html());
  }
  if(groupLength[group] > 1) {
    groupLength[group]--;
    $('#search'+group+'_'+groupLength[group]).remove();
    if(groupLength[group] == 1) {
      $('#group'+group+' .advSearchSearch .close').addClass('hidden'); // Hide x
      $('#group'+group+' .advSearchSearch .close').next().css('padding-right','initial');
      adjustAdvSearchTypeButtons();
    }
  }
}

function addGroup(firstTerm, firstField, join)
{
  if (firstTerm  == undefined) {firstTerm  = "Enter your search term";}
  if (firstField == undefined) {firstField = '';}
  if (join       == undefined) {join       = '';}

  var $newGroup = $($('#new_group_template').html());
  $newGroup.attr('id', 'group'+nextGroup);
  $newGroup.find('.search_place_holder')
    .attr('id', 'group'+nextGroup+'Holder')
    .removeClass('hidden');
  $newGroup.find('.add_search_link')
    .attr('id', 'add_search_link_'+nextGroup)
    .attr('onClick', 'addSearch('+nextGroup+'); return false;')
    .removeClass('hidden');
  $newGroup.children('.close')
    .attr('onClick', 'deleteGroup('+nextGroup+')');
  $newGroup.find('input.advSearchMatch')
    .attr('id', 'search_bool'+nextGroup)
    .attr('name', 'bool'+nextGroup+'[]');
  $newGroup.find('button.advSearchMatchButton')
    .attr('id', 'advSearchMatch'+nextGroup)
    .attr('data-target', '#advSearchMatch'+nextGroup+'Dropdown');
  $newGroup.find('button.advSearchMatchButton').nextAll('.dropdown')
    .attr('id', 'advSearchMatch'+nextGroup+'Dropdown')
  $newGroup.find('.search_bool')
    .attr('for', 'search_bool'+nextGroup);
  if(join.length > 0) {
    $newGroup.find('button.btnJoinValue' + join).click();
  }
  // Insert
  $('#groupPlaceHolder').before($newGroup);
  // Populate
  groupLength[nextGroup] = 0;
  addSearch(nextGroup, {term:firstTerm, field:firstField});
  // Show join menu
  if(nextGroup > 0) {
    $('#groupJoin').removeClass('hidden');
    $('#groupJoin').prev().addClass('EIN-col-t-6');
    // Show x
    $('.advSearchGroup').children('.close').removeClass('hidden');
  }
  return nextGroup++;
}

function deleteGroup(group)
{
  // Find the group and remove it
  $("#group" + group).remove();
  // If the last group was removed, add an empty group
  if($('.advSearchGroup').length == 0) {
    addGroup();
  } else if($('#advSearchForm .advSearchGroup ').length == 1) {
    $('#groupJoin').addClass('hidden'); // Hide join menu
    $('#groupJoin').prev().removeClass('EIN-col-t-6'); // Hide join menu
    $('.advSearchGroup').children('.close').addClass('hidden'); // Hide x
  }
}

// Fired by onclick event
function deleteGroupJS(group)
{
  var groupNum = group.id.replace("delete_link_", "");
  deleteGroup(groupNum);
  return false;
}

// Fired by onclick event
function addSearchJS(group)
{
  var groupNum = group.id.replace("add_search_link_", "");
  addSearch(groupNum);
  return false;
}

function ResetAdvSearchForm() {
  $('.advSearchSearch input[type="text"]').val('Enter your search term').css('color','#949494');
  $('.advSearchRange input.rangeFrom[type="text"]').val('Enter minimum').css('color','#949494');
  $('.advSearchRange input.rangeTo[type="text"]').val('Enter maximum').css('color','#949494');
  $("option:selected").removeAttr("selected");

  // reset the checkboxes
  $(".facetToggleOn:not(.categoryCheck) .fa-check-square").each( function() { $(this).parent().click() } );
  $("checkbox:selected").removeAttr("selected");
  $("#illustrated_-1").click();

  // reset dropdowns
  $(".advSearchTypeButton,.advSearchMatchButton,.advSearchJoinButton").each( function() { 
    $(this).next().find("button:first").click();
  } );
  $(".advSearchGroup:not(:first)").find("button:first").click();
  while( $(".EIN-col-m-12:not(.hidden) .advSearchSearch").length < 3 ) {
    $(".add_search_link").click();
  }
  while( $(".EIN-col-m-12:not(.hidden) .advSearchSearch").length > 3 ) {
    $(".EIN-col-m-12:not(.hidden) .advSearchSearch:last").find("button:first").css("display", "none");
    $(".EIN-col-m-12:not(.hidden) .advSearchSearch:last").find("button:first").click();
  }

  // reset slider
  $('.advSearchRange').find('.textBox').each( function() {
    $(this).val("????");
  } );
  var min = $('#publishDatedateSlider').data('slider').options.value[0];
  var max = $('#publishDatedateSlider').data('slider').options.value[1];
  $('#publishDatedateSlider').slider('setValue', [min, max]);
}

function adjustAdvSearchTypeButtons() {
  $('.advSearchTypeButton,.advSearchMatchButton,.advSearchJoinButton').each( function() { 
    if(($(this).parent().css("display") == "inline") && ($(this).parents('.hidden').length == 0) && ($(this).nextAll('.dropdown').children().outerWidth() > 0)) {
      $(this).parent().css({'display':'inline','position':'initial'});
      $(this).css('width', 'auto');
      $(this).nextAll('.dropdown').css({'left':'initial','position':'static'});
      setTimeout( function(item) {
        $(item).css('width', ($(item).nextAll('.dropdown').children().outerWidth() + 1));
        $(item).nextAll('.dropdown').css({'left':'0px','position':'absolute'});
        $(item).parent().css({'display':'inline-block','position':'relative'});
      }, 50, this);
    }
  } );
}

function CheckForEnter(e) {
  var isEnter = ((e.keyCode ? e.keyCode : (e.which ? e.which : e.charCode)) == 13);
  if( isEnter ) {
    $('#advSearchMasterButton').click();
  }
  return !isEnter;
}

function advSearchDropdownClick(button, value) {
  $(button).parents('.dropdown').prevAll('input').attr('value', value);
  $(button).parents('.dropdown').prevAll('button').children('span').html($(button).html());
}

function CleanSearchBoxes() {
  $('.advSearchSearch').find('.textBox').each( function() {
    if($(this).val() == "Enter your search term") {
      $(this).val("");
    }
  } );
  $('.advSearchRange').find('.textBox').each( function() {
    if(($(this).val() == "????")) {
      $(this).val("");
    }
  } );
}

jQuery(document).ready(function() {
  $('.advSearchTypeButton').nextAll('.dropdown').each( function() {
    if($(this).prevAll('input').val() == "") {
      $(this).find('li:first-child button').click();
    }
  } );
  CleanChecks();
} );

