


var layer = function() {
  cache.apply(this, arguments);
  this.get_class_names_()  
  if (this instanceof layer) {
    layer.layers.push(this);
    if (this.get_previous_layer()) {
      this.state = rocket.clone(this.get_previous_layer().state);
    }
  }
};
rocket.inherits(layer, cache);


layer.layers = [];


layer.prototype.get_previous_layer = function() {
  for (var i = 0; layer.layers[i]; ++i) {
    if (layer.layers[i] === this) {
      return layer.layers[i - 1];
    }
  }
};


layer.prototype.get_layers = function() {
  return layer.layers;
};


layer.prototype.get_class_names_ = function() {
  return this.constructor.prototype.class_names = this.class_names = this.class_names || 
    this.get_class_name_recursive_(window);
};


layer.prototype.get_class_name_recursive_ = function(parent, opt_prefix) {
  var name;
  for (var i in parent) {
    if (typeof parent[i] === 'function') {
      var name = opt_prefix ? rocket.clone(opt_prefix) : [];
      name.push(i);
      if (parent[i] === this.constructor) {
        return name;
      } else {
        if (
          (parent !== parent[i]) &&
          (name = this.get_class_name_recursive_(parent[i], name))
        ) {
          return name;
        }
      }
    }
  }
};


layer.prototype.layer_previous_container_;


layer.prototype.render = function(opt_parent) {
  var container = rocket.createElement('div');
  rocket.EventTarget.removeAllEventListeners();
  this.decorate(container);
  if (container.innerHTML()) {
    var containers = [];
    for (var i = 0; this.class_names[i]; ++i) {
      containers.push(rocket.createElement('div').addClass(this.class_names[i]));
      if (i) {
        containers[i - 1].appendChild(containers[i]);
      }
    }
    containers[containers.length - 1].appendChild(container);
    if (
      (this.layer_previous_container_) &&
      (this.layer_previous_container_.parentNode().length)
    ) {
      this.layer_previous_container_.parentNode().replaceChild(containers[0], this.layer_previous_container_);
    } else {
      (opt_parent || $('body').innerHTML('')).appendChild(containers[0]);
    }
    this.layer_previous_container_ = containers[0];
    this.dispatchEvent('rendered');
  }
};


layer.prototype.decorate = function() {};


layer.prototype.render_previous = function(opt_cancel) {
  if (opt_cancel) {
    layer.layers.pop();
    layer.layers[layer.layers.length - 1].render();
  } else {
    layer.layers[layer.layers.length - 2].state = this.state;
    rocket.EventTarget.removeAllEventListeners();
    this.flush(function() {
      layer.layers.pop();
      layer.layers[layer.layers.length - 1].render();
    });
  }
};

layer.prototype.render_clear = function() {
  this.render();
  layer.layers = [this];
};
